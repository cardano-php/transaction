<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Script;

use Brick\Math\BigInteger;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\EnterpriseAddress;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Exception\ScriptException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Ledger\Slot;
use CBOR\ByteStringObject;
use CBOR\CBORObject;
use CBOR\ListObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;

/**
 * A native script, and the hash the chain knows it by.
 *
 * The Shelley-MA ledger CDDL writes one as an array whose first item names the constructor:
 *
 *   native_script     = script_pubkey / script_all / script_any / script_n_of_k
 *                     / invalid_before / invalid_hereafter
 *   script_pubkey     = (0, addr_keyhash)
 *   script_all        = (1, [* native_script])
 *   script_any        = (2, [* native_script])
 *   script_n_of_k     = (3, int, [* native_script])
 *   invalid_before    = (4, slot_no)
 *   invalid_hereafter = (5, slot_no)
 *
 * The two time constructors are named from the transaction's point of view and cardano-cli renames them from the
 * script's, which is where they cross over. `invalid_before` is the ledger saying the transaction is invalid before a
 * slot, so the cli calls it `after`; `invalid_hereafter` is the ledger saying it is invalid from a slot onwards, so
 * the cli calls it `before`. This class uses the cli names, because they are what a script file is written in.
 *
 * What each one asks of a transaction is the part worth getting right. `after` is satisfied when the transaction's
 * validity interval *starts* at or after its slot; `before` when the interval *ends* at or before its slot. Written
 * the other way round, a minting policy either never mints at all or only starts minting once the campaign it was
 * built for has finished. isSatisfiedBy() is the same rule as an assertion rather than as a comment.
 *
 * A list of sub-scripts has two valid framings and neither is canonical, so a container holding 24 or more of them
 * has two valid hashes and two valid addresses. Framing says which side a script is on. Building takes the framing
 * cardano-binary writes, so a hash derived here from a script file is the hash cardano-cli prints for it, and a
 * caller talking to the JavaScript ecosystem asks for the other. Reading keeps the framing the bytes arrived in, so
 * a script decoded here re-encodes to the bytes it came from and keeps the hash the chain published for it.
 *
 * The grammar admits a container holding no sub-scripts and a threshold at or below zero, the chain carries both, and
 * cardano-cli builds both, so this class builds them too. What they mean is worth knowing before building one. An
 * `all` with no sub-scripts has no condition left to fail, so every transaction satisfies it and anyone can spend what
 * it guards; an `atLeast` whose threshold is zero or negative is the same script written differently. An `any` with no
 * sub-scripts has no branch that can succeed, so nothing satisfies it and what it guards is frozen. A threshold above
 * the number of sub-scripts beside it is refused, because cardano-cli refuses it too.
 */
final class NativeScript
{
    public const SIG = 'sig';

    public const ALL = 'all';

    public const ANY = 'any';

    public const AT_LEAST = 'atLeast';

    public const BEFORE = 'before';

    public const AFTER = 'after';

    /** The constructor tag each kind is written with, from the CDDL above. */
    private const TAGS = [
        self::SIG => 0,
        self::ALL => 1,
        self::ANY => 2,
        self::AT_LEAST => 3,
        self::AFTER => 4,
        self::BEFORE => 5,
    ];

    /**
     * The language tag a script hash is taken over.
     *
     * Native scripts are 0x00 and the Plutus versions are 0x01 upwards over the same hash function. Dropping the byte
     * yields a digest that is the right length, looks like a script hash, and belongs to nothing.
     */
    public const LANGUAGE_TAG = "\x00";

    /** The largest slot number that exists, which is 2^64-1. Slot says why that is the bound. */
    public const SLOT_MAX = Slot::MAX;

    /** The framing a script gets when the caller does not say, which is the one cardano-cli writes. */
    public const DEFAULT_FRAMING = Framing::CardanoBinary;

    /**
     * @param  list<self>  $scripts
     * @param  SequenceForm|null  $childForm  how the list of sub-scripts is framed, and null for a leaf
     */
    private function __construct(
        public readonly string $kind,
        private readonly array $scripts = [],
        private readonly ?Credential $key = null,
        private readonly ?BigInteger $slot = null,
        private readonly ?int $required = null,
        private readonly ?SequenceForm $childForm = null,
    ) {}

    // ------------------------------------------------------------------ building

    public static function sig(string $keyHashHex): self
    {
        return new self(self::SIG, key: Credential::keyHash($keyHashHex));
    }

    /**
     * The same, for a caller who already holds the credential.
     */
    public static function signedBy(Credential $key): self
    {
        if ($key->isScript()) {
            throw new ScriptException('A sig clause names a key hash, not a script hash.');
        }

        return new self(self::SIG, key: $key);
    }

    /**
     * An `all` clause, which is satisfied when every sub-script beside it is.
     *
     * With no sub-scripts there is nothing left to satisfy, so the script is satisfied by every transaction and
     * anyone can spend what it guards. That script is on mainnet, cardano-cli builds it, and so does this.
     */
    public static function all(self ...$scripts): self
    {
        return self::container(self::ALL, array_values($scripts));
    }

    /**
     * An `any` clause, which is satisfied when one sub-script beside it is.
     *
     * With no sub-scripts there is no branch that can succeed, so nothing satisfies it and what it guards cannot be
     * spent at all.
     */
    public static function any(self ...$scripts): self
    {
        return self::container(self::ANY, array_values($scripts));
    }

    /**
     * An `atLeast` clause, satisfied when $required of the sub-scripts beside it are.
     *
     * A threshold at or below zero is already met before anything is counted, which makes the script satisfied by
     * every transaction. The ledger reads the threshold as a signed 64-bit integer and cardano-cli writes whatever it
     * is given, so this does the same. A threshold above the number of sub-scripts is the one case the whole
     * ecosystem refuses: it can never be met, and cardano-cli says so rather than building it.
     */
    public static function atLeast(int $required, self ...$scripts): self
    {
        $children = array_values($scripts);
        self::checkThreshold($required, count($children));

        return self::container(self::AT_LEAST, $children, $required);
    }

    /**
     * Satisfied only by a transaction whose validity interval ends at or before $slot. Encodes as invalid_hereafter.
     *
     * A slot is a uint64, which runs past what a PHP integer holds, so one above 2^63-1 is given as a decimal string
     * or as a BigInteger. An ordinary slot is an ordinary integer and none of this has to be thought about.
     */
    public static function before(int|string|BigInteger $slot): self
    {
        return new self(self::BEFORE, slot: self::checkedSlot($slot));
    }

    /**
     * Satisfied only by a transaction whose validity interval starts at or after $slot. Encodes as invalid_before.
     */
    public static function after(int|string|BigInteger $slot): self
    {
        return new self(self::AFTER, slot: self::checkedSlot($slot));
    }

    /**
     * The same script with every container framed the way $framing frames it.
     *
     * This is how a caller building from the builders picks a side. Below 24 sub-scripts the two framings are
     * byte-identical and this changes nothing; at or above it the bytes move, and the hash and the address with them.
     */
    public function framed(Framing $framing): self
    {
        if ($this->childForm === null) {
            return $this;
        }

        return new self(
            $this->kind,
            array_map(static fn (self $script): self => $script->framed($framing), $this->scripts),
            required: $this->required,
            childForm: $framing->formFor(count($this->scripts)),
        );
    }

    /**
     * The framings that would reproduce this script's bytes, which is the question to ask of a script that was read.
     *
     * Both of them means every container holds fewer than 24 sub-scripts, so the two encoders agree and there is one
     * hash. One of them names the encoder these bytes came from. Neither of them means the bytes are framed in a way
     * no standard encoder produces, which a script built here never is and a script read here can only be if it
     * arrived that way.
     *
     * @return list<Framing>
     */
    public function framings(): array
    {
        return array_values(array_filter(
            Framing::cases(),
            fn (Framing $framing): bool => $this->isFramedAs($framing)
        ));
    }

    // ------------------------------------------------------------------- reading

    /**
     * A script in the JSON form cardano-cli reads from a script file and providers return from /script_info.
     *
     * The JSON says nothing about framing, because framing is a property of the bytes rather than of the script, so
     * the caller says which encoder it is standing in for. A slot may be written as a JSON number or as a decimal
     * string; a string is the only way to carry one above 2^63-1 through PHP, where json_decode turns a larger
     * literal into a float and loses it.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json, Framing $framing = self::DEFAULT_FRAMING): self
    {
        $kind = $json['type'] ?? null;

        if (! is_string($kind) || ! isset(self::TAGS[$kind])) {
            throw new ScriptException('Unknown native script type: '.var_export($kind, true));
        }

        if ($kind === self::SIG) {
            $keyHash = $json['keyHash'] ?? null;

            if (! is_string($keyHash)) {
                throw new ScriptException('A sig clause carries a keyHash.');
            }

            return self::sig($keyHash);
        }

        if ($kind === self::BEFORE || $kind === self::AFTER) {
            $slot = $json['slot'] ?? null;

            if (! is_int($slot) && ! is_string($slot)) {
                throw new ScriptException(sprintf('A %s clause carries a slot number.', $kind));
            }

            return $kind === self::BEFORE ? self::before($slot) : self::after($slot);
        }

        $children = $json['scripts'] ?? null;

        if (! is_array($children)) {
            throw new ScriptException(sprintf('A %s clause carries a list of scripts.', $kind));
        }

        $scripts = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new ScriptException(sprintf('A %s clause holds scripts, not scalars.', $kind));
            }

            $scripts[] = self::fromArray($child, $framing);
        }

        if ($kind === self::AT_LEAST) {
            $required = $json['required'] ?? null;

            if (! is_int($required)) {
                throw new ScriptException('An atLeast clause carries a required count.');
            }

            self::checkThreshold($required, count($scripts));

            return self::container(self::AT_LEAST, $scripts, $required, $framing);
        }

        return self::container($kind, $scripts, null, $framing);
    }

    public static function fromJson(string $json, Framing $framing = self::DEFAULT_FRAMING): self
    {
        // A slot above 2^63-1 is a JSON number PHP cannot hold. Reading it as a string keeps every digit of it, and
        // the slot check turns it back into a number rather than leaving a string in the model.
        $decoded = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($decoded)) {
            throw new ScriptException('A native script file holds a JSON object.');
        }

        return self::fromArray($decoded, $framing);
    }

    /**
     * A script from the bytes a witness set or a reference script carries.
     *
     * The framing each container arrived in is kept, so re-encoding the script reproduces the bytes it was read from
     * and the hash stays the hash whoever wrote it published. That covers both framings in circulation, which is the
     * whole of what the ecosystem writes, and a script mixing the two as well.
     *
     * What is left is bytes that are a native script but are written in a way no encoder produces, an integer carried
     * in a wider head than it needs being the usual one. Those are refused rather than rewritten, because rewriting
     * them would move the hash and the address with it. They still have a hash, and WitnessSet::nativeScriptHashes()
     * takes it over the bytes as they arrived without decoding them at all.
     */
    public static function fromCbor(string $bytes): self
    {
        try {
            $script = self::read(CborCodec::decode($bytes), 'native script');
        } catch (DecodeException $e) {
            throw new ScriptException('Not a native script: '.$e->getMessage(), 0, $e);
        }

        if ($script->cbor() !== $bytes) {
            throw new ScriptException(
                'This script is written in a way this package cannot reproduce, so re-encoding it would change its '
                .'hash. Hash the bytes it arrived in instead.'
            );
        }

        return $script;
    }

    private static function read(CBORObject $object, string $context): self
    {
        // A script node is an array of two or three items and every encoder writes that array definite in length, so
        // the form it was written in is not kept. A node framed any other way fails the byte comparison in fromCbor().
        [, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if ($items === []) {
            throw new DecodeException($context.': a native script names a constructor.');
        }

        $tag = CborInteger::unsignedFromCbor($items[0], $context.' constructor')->toInt();
        $kind = array_search($tag, self::TAGS, true);

        if ($kind === false) {
            throw new DecodeException(sprintf('%s: no native script constructor %d.', $context, $tag));
        }

        $expected = $kind === self::AT_LEAST ? 3 : 2;

        if (count($items) !== $expected) {
            throw new DecodeException(sprintf(
                '%s: a %s clause is %d items, got %d.',
                $context,
                $kind,
                $expected,
                count($items)
            ));
        }

        if ($kind === self::SIG) {
            return self::sig(bin2hex(Shape::bytes($items[1], $context.' key hash', Blake2b::DIGEST_CREDENTIAL)));
        }

        if ($kind === self::BEFORE || $kind === self::AFTER) {
            // A CBOR unsigned integer stops at 2^64-1, and a bignum above it is a tag rather than an integer and is
            // refused here. Reading the value as a decimal string keeps the half of the range PHP cannot hold.
            $slot = CborInteger::unsignedFromCbor($items[1], $context.' slot')->value;

            return $kind === self::BEFORE ? self::before($slot) : self::after($slot);
        }

        $listIndex = $kind === self::AT_LEAST ? 2 : 1;
        [$childForm, $children] = SequenceForm::unwrap($items[$listIndex], $context.' scripts', allowSetTag: false);

        $scripts = [];
        foreach ($children as $i => $child) {
            $scripts[] = self::read($child, sprintf('%s script %d', $context, $i));
        }

        if ($kind === self::AT_LEAST) {
            // The threshold is a signed integer in the CDDL, and a negative one is a script the ledger accepts.
            $required = CborInteger::fromCbor($items[1], $context.' threshold')->toInt();
            self::checkThreshold($required, count($scripts));

            return new self(self::AT_LEAST, $scripts, required: $required, childForm: $childForm);
        }

        return new self($kind, $scripts, childForm: $childForm);
    }

    // ------------------------------------------------------------------ encoding

    /**
     * The structure cardano-cli reads from a script file, ready for json_encode.
     *
     * A slot comes back as an integer when one holds it and as a decimal string when it does not, which is only ever
     * above 2^63-1. fromArray() reads both.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            self::SIG => ['type' => self::SIG, 'keyHash' => $this->key?->hex()],
            self::BEFORE, self::AFTER => ['type' => $this->kind, 'slot' => self::slotForJson($this->slot)],
            self::AT_LEAST => [
                'type' => self::AT_LEAST,
                'required' => $this->required,
                'scripts' => array_map(static fn (self $s): array => $s->toArray(), $this->scripts),
            ],
            default => [
                'type' => $this->kind,
                'scripts' => array_map(static fn (self $s): array => $s->toArray(), $this->scripts),
            ],
        };
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function toCbor(): CBORObject
    {
        $tag = UnsignedIntegerObject::create(self::TAGS[$this->kind]);

        return match ($this->kind) {
            self::SIG => ListObject::create([$tag, ByteStringObject::create((string) $this->key?->hash)]),
            self::BEFORE, self::AFTER => ListObject::create([
                $tag,
                CborInteger::of((string) $this->slot)->toCbor(),
            ]),
            self::AT_LEAST => ListObject::create([
                $tag,
                self::thresholdObject((int) $this->required),
                $this->childList(),
            ]),
            default => ListObject::create([$tag, $this->childList()]),
        };
    }

    /** The serialized script, as raw bytes. */
    public function cbor(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    public function cborHex(): string
    {
        return bin2hex($this->cbor());
    }

    // ------------------------------------------------------------------- hashing

    /**
     * blake2b-224 over the language tag byte followed by the script CBOR.
     */
    public function hash(): string
    {
        return Blake2b::hash224(self::LANGUAGE_TAG.$this->cbor());
    }

    public function hashHex(): string
    {
        return bin2hex($this->hash());
    }

    /**
     * The policy identifier of a minting policy is its script hash. There is nothing further to compute.
     */
    public function policyId(): string
    {
        return $this->hashHex();
    }

    public function credential(): Credential
    {
        return Credential::scriptHashBytes($this->hash());
    }

    /** The address funds sit at when the script alone controls them. */
    public function enterpriseAddress(Network $network): EnterpriseAddress
    {
        return EnterpriseAddress::of($network, $this->credential());
    }

    /** The same script, with stake rights delegated through a second credential. A different address. */
    public function baseAddress(Network $network, Credential $delegation): BaseAddress
    {
        return BaseAddress::of($network, $this->credential(), $delegation);
    }

    /**
     * The address this script pays to: enterprise with no delegation credential, base with one.
     */
    public function address(Network $network, ?Credential $delegation = null): Address
    {
        return $delegation === null
            ? $this->enterpriseAddress($network)
            : $this->baseAddress($network, $delegation);
    }

    // -------------------------------------------------------------- what it asks

    /**
     * Whether a transaction satisfies this script.
     *
     * $signers are the key hashes whose signatures the transaction carries, as hex. $intervalStart is the
     * transaction's validity interval start and $intervalEnd its end, both as the body writes them, and either may be
     * absent. Each of those is a slot, so each takes the values a slot takes and is refused outside them. The
     * ledger's own rule for the two time constructors is that an absent bound satisfies nothing: a transaction that
     * sets no upper bound has not shown that it will be applied before any particular slot, so a `before` clause
     * above it fails.
     *
     * @param  list<string>  $signers
     */
    public function isSatisfiedBy(
        array $signers,
        int|string|BigInteger|null $intervalStart = null,
        int|string|BigInteger|null $intervalEnd = null,
    ): bool {
        return $this->satisfied(
            $signers,
            $intervalStart === null ? null : self::checkedSlot($intervalStart),
            $intervalEnd === null ? null : self::checkedSlot($intervalEnd),
        );
    }

    /**
     * The slot a `before` or an `after` names, and null for every other kind.
     */
    public function slot(): ?BigInteger
    {
        return $this->slot;
    }

    /**
     * Every key hash named anywhere in the script, in the order it appears.
     *
     * A hash named twice appears twice, because how many times a script names a key is part of what it says.
     *
     * @return list<string>
     */
    public function keyHashes(): array
    {
        if ($this->kind === self::SIG) {
            return [(string) $this->key?->hex()];
        }

        $hashes = [];
        foreach ($this->scripts as $script) {
            foreach ($script->keyHashes() as $hash) {
                $hashes[] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * The key hashes that can satisfy this script with no time bound above them, in the order they appear.
     *
     * This is the property a campaign script exists to express: the unbounded signer is whoever owns the money.
     * Reading it off the JSON by eye is easy to get wrong once the nesting is more than one level deep, which is
     * exactly where a mistake would go unnoticed.
     *
     * @return list<string>
     */
    public function unboundedSigners(): array
    {
        return $this->partitionSigners()[0];
    }

    /**
     * The key hashes that can only sign inside a time bound, in the order they appear.
     *
     * @return list<string>
     */
    public function timeBoundSigners(): array
    {
        return $this->partitionSigners()[1];
    }

    /** True when every way of satisfying this sub-script involves a time bound. */
    public function isTimeBound(): bool
    {
        return match ($this->kind) {
            self::SIG => false,
            self::BEFORE, self::AFTER => true,
            // Every branch has to be satisfied, so one bounded branch binds all of them.
            self::ALL => array_reduce($this->scripts, static fn (bool $c, self $s): bool => $c || $s->isTimeBound(), false),
            // The branches are alternatives, so an unbounded one escapes the bound.
            self::ANY => array_reduce($this->scripts, static fn (bool $c, self $s): bool => $c && $s->isTimeBound(), true),
            // Bounded unless enough unbounded branches remain to meet the threshold.
            default => count(array_filter($this->scripts, static fn (self $s): bool => ! $s->isTimeBound())) < (int) $this->required,
        };
    }

    // -------------------------------------------------------------------- detail

    /**
     * A container, framed the way $framing frames a list of that many sub-scripts.
     *
     * @param  list<self>  $scripts
     */
    private static function container(
        string $kind,
        array $scripts,
        ?int $required = null,
        Framing $framing = self::DEFAULT_FRAMING,
    ): self {
        return new self(
            $kind,
            $scripts,
            required: $required,
            childForm: $framing->formFor(count($scripts)),
        );
    }

    private function isFramedAs(Framing $framing): bool
    {
        if ($this->childForm === null) {
            return true;
        }

        if (! $framing->frames($this->childForm, count($this->scripts))) {
            return false;
        }

        foreach ($this->scripts as $script) {
            if (! $script->isFramedAs($framing)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $signers
     */
    private function satisfied(array $signers, ?BigInteger $intervalStart, ?BigInteger $intervalEnd): bool
    {
        return match ($this->kind) {
            self::SIG => in_array($this->key?->hex(), $signers, true),
            self::AFTER => $intervalStart !== null && $intervalStart->isGreaterThanOrEqualTo((string) $this->slot),
            self::BEFORE => $intervalEnd !== null && $intervalEnd->isLessThanOrEqualTo((string) $this->slot),
            self::ALL => $this->satisfiedCount($signers, $intervalStart, $intervalEnd) === count($this->scripts),
            self::ANY => $this->satisfiedCount($signers, $intervalStart, $intervalEnd) > 0,
            default => $this->satisfiedCount($signers, $intervalStart, $intervalEnd) >= (int) $this->required,
        };
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function partitionSigners(): array
    {
        $unbounded = [];
        $bound = [];
        $this->collectSigners(false, $unbounded, $bound);

        return [$unbounded, $bound];
    }

    /**
     * @param  list<string>  $unbounded
     * @param  list<string>  $bound
     */
    private function collectSigners(bool $bounded, array &$unbounded, array &$bound): void
    {
        if ($this->kind === self::SIG) {
            if ($bounded) {
                $bound[] = (string) $this->key?->hex();
            } else {
                $unbounded[] = (string) $this->key?->hex();
            }

            return;
        }

        if ($this->kind === self::BEFORE || $this->kind === self::AFTER) {
            return;
        }

        $childrenAreBounded = $bounded || ($this->kind !== self::ANY && $this->isTimeBound());

        foreach ($this->scripts as $script) {
            $script->collectSigners($childrenAreBounded, $unbounded, $bound);
        }
    }

    /**
     * @param  list<string>  $signers
     */
    private function satisfiedCount(array $signers, ?BigInteger $intervalStart, ?BigInteger $intervalEnd): int
    {
        $met = 0;

        foreach ($this->scripts as $script) {
            if ($script->satisfied($signers, $intervalStart, $intervalEnd)) {
                $met++;
            }
        }

        return $met;
    }

    /**
     * The sub-scripts, framed the way this container was built or read.
     */
    private function childList(): CBORObject
    {
        return ($this->childForm ?? SequenceForm::definite())->wrap(
            array_map(static fn (self $s): CBORObject => $s->toCbor(), $this->scripts)
        );
    }

    /**
     * The threshold, written as the signed integer the CDDL says it is.
     */
    private static function thresholdObject(int $required): CBORObject
    {
        return $required < 0
            ? NegativeIntegerObject::create($required)
            : UnsignedIntegerObject::create($required);
    }

    /**
     * The one degenerate shape the whole ecosystem refuses, checked wherever a threshold arrives.
     */
    private static function checkThreshold(int $required, int $childCount): void
    {
        if ($required > $childCount) {
            throw new ScriptException(sprintf(
                'Required number of script signatures exceeds the number of scripts: %d of %d.',
                $required,
                $childCount
            ));
        }
    }

    /**
     * A slot, as the number it is rather than as whatever PHP can hold of it.
     *
     * The range is the CDDL's: an integer from 0 to 2^64-1. Outside it the script is undecodable, which is worse than
     * unsatisfiable, because it still has an address and whatever is sent there can never be moved by anybody. A slot
     * above 2^63-1 does not fit a PHP integer, so it is given as a decimal string or as a BigInteger and is carried
     * as a BigInteger from here on.
     */
    private static function checkedSlot(int|string|BigInteger $slot): BigInteger
    {
        return Slot::parse($slot) ?? throw new ScriptException(Slot::complaint($slot));
    }

    private static function slotForJson(?BigInteger $slot): int|string|null
    {
        if ($slot === null) {
            return null;
        }

        return $slot->isLessThanOrEqualTo(PHP_INT_MAX) ? $slot->toInt() : (string) $slot;
    }
}
