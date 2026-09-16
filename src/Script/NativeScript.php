<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Script;

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
 * Encoding follows the ledger's own encoder. Every head is the shortest that holds its value, and a list of
 * sub-scripts is written definite in length up to 23 items and indefinite above that, which is where
 * cardano-ledger-binary switches. That is not a style choice. The hash is taken over these bytes, the hash is the
 * address, and an address has no migration, so a second encoder that agreed about the meaning and disagreed about the
 * bytes would send funds somewhere nobody is watching. cardano-node and cardano-cli serialize through that encoder,
 * so a script built here from the JSON of a script file hashes to the hash cardano-cli prints for it, at every width.
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

    /**
     * @param  list<self>  $scripts
     */
    private function __construct(
        public readonly string $kind,
        private readonly array $scripts = [],
        private readonly ?Credential $key = null,
        private readonly ?int $slot = null,
        private readonly ?int $required = null,
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
        return new self(self::ALL, array_values($scripts));
    }

    /**
     * An `any` clause, which is satisfied when one sub-script beside it is.
     *
     * With no sub-scripts there is no branch that can succeed, so nothing satisfies it and what it guards cannot be
     * spent at all.
     */
    public static function any(self ...$scripts): self
    {
        return new self(self::ANY, array_values($scripts));
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
        if ($required > count($scripts)) {
            throw new ScriptException(sprintf(
                'Required number of script signatures exceeds the number of scripts: %d of %d.',
                $required,
                count($scripts)
            ));
        }

        return new self(self::AT_LEAST, array_values($scripts), required: $required);
    }

    /**
     * Satisfied only by a transaction whose validity interval ends at or before $slot. Encodes as invalid_hereafter.
     */
    public static function before(int $slot): self
    {
        return new self(self::BEFORE, slot: self::slot($slot));
    }

    /**
     * Satisfied only by a transaction whose validity interval starts at or after $slot. Encodes as invalid_before.
     */
    public static function after(int $slot): self
    {
        return new self(self::AFTER, slot: self::slot($slot));
    }

    // ------------------------------------------------------------------- reading

    /**
     * A script in the JSON form cardano-cli reads from a script file and providers return from /script_info.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromArray(array $json): self
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

            if (! is_int($slot)) {
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

            $scripts[] = self::fromArray($child);
        }

        if ($kind === self::AT_LEAST) {
            $required = $json['required'] ?? null;

            if (! is_int($required)) {
                throw new ScriptException('An atLeast clause carries a required count.');
            }

            return self::atLeast($required, ...$scripts);
        }

        return $kind === self::ALL ? self::all(...$scripts) : self::any(...$scripts);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new ScriptException('A native script file holds a JSON object.');
        }

        return self::fromArray($decoded);
    }

    /**
     * A script from the bytes a witness set or a reference script carries.
     *
     * The bytes have to be the encoding this class writes, which is the ledger's own. A script written any other way
     * still hashes to whatever the chain knows it by, but this model would re-encode it and produce a different hash,
     * so it is refused here rather than silently rewritten. That covers a wide container framed definite, which some
     * other libraries write and the ledger accepts. The hash of such a script is the hash of the bytes it arrived in,
     * which is what WitnessSet::nativeScriptHashes() takes.
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
                'This script is not written in the encoding this package produces, so re-encoding it would change '
                .'its hash. Hash the bytes it arrived in instead.'
            );
        }

        return $script;
    }

    private static function read(CBORObject $object, string $context): self
    {
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
            $slot = CborInteger::unsignedFromCbor($items[1], $context.' slot')->toInt();

            return $kind === self::BEFORE ? self::before($slot) : self::after($slot);
        }

        $listIndex = $kind === self::AT_LEAST ? 2 : 1;
        [, $children] = SequenceForm::unwrap($items[$listIndex], $context.' scripts', allowSetTag: false);

        $scripts = [];
        foreach ($children as $i => $child) {
            $scripts[] = self::read($child, sprintf('%s script %d', $context, $i));
        }

        if ($kind === self::AT_LEAST) {
            // The threshold is a signed integer in the CDDL, and a negative one is a script the ledger accepts.
            return self::atLeast(
                CborInteger::fromCbor($items[1], $context.' threshold')->toInt(),
                ...$scripts
            );
        }

        return $kind === self::ALL ? self::all(...$scripts) : self::any(...$scripts);
    }

    // ------------------------------------------------------------------ encoding

    /**
     * The structure cardano-cli reads from a script file, ready for json_encode.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            self::SIG => ['type' => self::SIG, 'keyHash' => $this->key?->hex()],
            self::BEFORE, self::AFTER => ['type' => $this->kind, 'slot' => $this->slot],
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
                UnsignedIntegerObject::create((int) $this->slot),
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
     * absent. The ledger's own rule for the two time constructors is that an absent bound satisfies nothing: a
     * transaction that sets no upper bound has not shown that it will be applied before any particular slot, so a
     * `before` clause above it fails.
     *
     * @param  list<string>  $signers
     */
    public function isSatisfiedBy(array $signers, ?int $intervalStart = null, ?int $intervalEnd = null): bool
    {
        return match ($this->kind) {
            self::SIG => in_array($this->key?->hex(), $signers, true),
            self::AFTER => $intervalStart !== null && $intervalStart >= $this->slot,
            self::BEFORE => $intervalEnd !== null && $intervalEnd <= $this->slot,
            self::ALL => $this->satisfiedCount($signers, $intervalStart, $intervalEnd) === count($this->scripts),
            self::ANY => $this->satisfiedCount($signers, $intervalStart, $intervalEnd) > 0,
            default => $this->satisfiedCount($signers, $intervalStart, $intervalEnd) >= (int) $this->required,
        };
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
    private function satisfiedCount(array $signers, ?int $intervalStart, ?int $intervalEnd): int
    {
        $met = 0;

        foreach ($this->scripts as $script) {
            if ($script->isSatisfiedBy($signers, $intervalStart, $intervalEnd)) {
                $met++;
            }
        }

        return $met;
    }

    /**
     * The sub-scripts, framed the way the ledger's encoder frames a list of that many items.
     */
    private function childList(): CBORObject
    {
        return SequenceForm::forLedgerLength(count($this->scripts))->wrap(
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

    private static function slot(int $slot): int
    {
        if ($slot < 0) {
            throw new ScriptException('A slot number cannot be negative, got: '.$slot);
        }

        return $slot;
    }
}
