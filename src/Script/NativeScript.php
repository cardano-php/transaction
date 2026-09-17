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
use Cardano\Transaction\Cbor\CborHead;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Exception\ScriptException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Ledger\Slot;
use Closure;

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
 *
 * Nothing in the grammar bounds how deep a script nests, and the chain carries them thousands of levels deep, so
 * every traversal in here walks the tree on a stack of its own rather than on the call stack. MAX_DEPTH says how
 * deep this package reads, and where that number comes from.
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
     * The transaction size limit, which is what actually bounds a native script.
     *
     * `maxTxSize` is 16,384 bytes on mainnet, preprod and preview alike, and the epoch parameters under
     * tests/fixtures/cardano-ledger record it. It is a protocol parameter, so governance can move it, and every
     * number below is a consequence of its present value rather than a property of native scripts.
     */
    public const MAX_TRANSACTION_BYTES = 16384;

    /**
     * The fewest bytes one level of nesting can cost.
     *
     * An `all` wrapping exactly one sub-script is `82 01 81`: the two-item array head, the constructor tag, and the
     * head of a list holding one thing. `any` is the same three bytes, `atLeast` is four, an indefinite length list
     * is four once its break byte is counted, and a wider list head only costs more. Three is the floor.
     */
    public const MIN_BYTES_PER_LEVEL = 3;

    /**
     * How deep a script this package reads, which is as deep as a transaction could carry one.
     *
     * A native script reaches the chain only inside a transaction. It travels in a witness set, or in an output as a
     * reference script, or in the auxiliary data, and all three are weighed against `maxTxSize`. A reference script
     * is no exception: the transaction that creates it carries the whole script in one of its outputs, so that route
     * buys many scripts in one transaction rather than one larger script. So a script on chain is at most
     * MAX_TRANSACTION_BYTES long, and at MIN_BYTES_PER_LEVEL bytes a level that is 5,461 levels of nesting.
     *
     * Nothing can actually reach it. A script of 5,461 levels is 16,383 of the 16,384 bytes and leaves one byte for
     * the transaction around it, and the deepest a node has accepted is 5,383, in preprod transaction
     * f90dce5765108da976abdbb9fc618f9a6ffd9fa4d93b2f288eed1808545424c9, where 5,384 was refused for size and the
     * refusal named the size rather than the script. The seventy-eight levels between the two are the margin.
     *
     * More margin than that would cost more than it bought. A script is a tree of objects however it was read, and
     * PHP frees a tree of objects by recursing into it, on whatever stack the process was given: the 8 MB a Linux
     * process gets by default runs out somewhere past fifty thousand levels, and 1 MB runs out near six thousand,
     * both without an exception and without a message. Reading deeper than a transaction can deliver would mean
     * handing back scripts that could not have come from a chain and that take the process with them when they are
     * released. Stopping where the ledger stops is the deepest that can be both promised and survived.
     */
    public const MAX_DEPTH = 5461;

    /**
     * How deep a script the JSON form carries, which is PHP's limit rather than this package's.
     *
     * PHP's JSON reader and writer both nest 512 levels by default and a script spends two of them per level, the
     * object and the list of sub-scripts inside it. Raising the allowance does not buy much: the reader underneath
     * gives out near five thousand levels of nesting whatever it is told, and a pretty-printed script grows with the
     * square of its depth because every level indents. CBOR is the form the chain carries and the form to read a deep
     * script from. JSON is how a script file is written, and script files are shallow.
     */
    public const MAX_JSON_DEPTH = 255;

    /** The nesting PHP is allowed for the JSON form: the object and the sub-script list of every level, and a root. */
    private const JSON_NESTING = 2 * self::MAX_JSON_DEPTH + 2;

    /** How many containers deep the longest path through this script runs. A leaf is nought. */
    private readonly int $depth;

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
    ) {
        $deepest = 0;

        foreach ($scripts as $script) {
            if ($script->depth > $deepest) {
                $deepest = $script->depth;
            }
        }

        $this->depth = $childForm === null ? 0 : $deepest + 1;

        if ($this->depth > self::MAX_DEPTH) {
            throw self::tooDeep($this->depth);
        }
    }

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

        return $this->fold(static fn (self $node, array $children): self => $node->childForm === null
            ? $node
            : new self(
                $node->kind,
                $children,
                required: $node->required,
                childForm: $framing->formFor(count($children)),
            ));
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
        /** @var list<array{kind: string, required: ?int, children: list<self>, raw: list<mixed>}> $stack */
        $stack = [];
        $pending = $json;
        $expand = true;
        $value = null;

        while (true) {
            if ($expand) {
                $expand = false;
                [$kind, $leaf, $required, $raw] = self::readJsonNode($pending);

                if ($leaf !== null) {
                    $value = $leaf;
                } elseif ($raw === []) {
                    $value = self::containerFromJson($kind, [], $required, $framing);
                } else {
                    $stack[] = ['kind' => $kind, 'required' => $required, 'children' => [], 'raw' => $raw];
                    $pending = $raw[0];
                    $expand = true;
                }

                continue;
            }

            if ($stack === []) {
                return $value;
            }

            $top = count($stack) - 1;
            $stack[$top]['children'][] = $value;
            $next = count($stack[$top]['children']);

            if ($next < count($stack[$top]['raw'])) {
                $pending = $stack[$top]['raw'][$next];
                $expand = true;

                continue;
            }

            $frame = array_pop($stack);
            $value = self::containerFromJson($frame['kind'], $frame['children'], $frame['required'], $framing);
        }
    }

    public static function fromJson(string $json, Framing $framing = self::DEFAULT_FRAMING): self
    {
        // A slot above 2^63-1 is a JSON number PHP cannot hold. Reading it as a string keeps every digit of it, and
        // the slot check turns it back into a number rather than leaving a string in the model.
        $decoded = json_decode($json, true, self::JSON_NESTING, JSON_BIGINT_AS_STRING);

        if (json_last_error() === JSON_ERROR_DEPTH) {
            throw new ScriptException(sprintf(
                'A native script file nests deeper than %d levels, which is the most PHP reads as JSON. Read the '
                .'script from its CBOR instead.',
                self::MAX_JSON_DEPTH
            ));
        }

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
     *
     * The bytes are walked here directly rather than being turned into CBOR values first. What the reader gains by
     * that is the script's own model straight out of the bytes, with no intermediate tree twice as deep as the
     * script to build and release. Reading the same script through CborCodec reaches the same depth and gives back
     * the same bytes, which is what lets a transaction carry one of these in its witness set.
     */
    public static function fromCbor(string $bytes): self
    {
        try {
            $script = self::readBytes($bytes);
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

    /**
     * The whole input as one script, with nothing left over.
     */
    private static function readBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new DecodeException('Empty input.');
        }

        $offset = 0;
        $script = self::readScript($bytes, $offset);
        $remaining = strlen($bytes) - $offset;

        if ($remaining !== 0) {
            throw new DecodeException(sprintf(
                'Malformed CBOR: %d byte(s) follow the top level item.',
                $remaining
            ));
        }

        return $script;
    }

    /**
     * One script, read out of $bytes from $offset, on a stack of open containers rather than on the call stack.
     *
     * A container cannot be built until every sub-script under it has been, but the framing of its list is read from
     * the bytes before any of them is, so each frame is both a half-built container and this reader's own position in
     * the byte stream. `$expand` says the next step opens a fresh node; otherwise a node has just been finished and
     * belongs to the frame on top.
     *
     * Where the reader is, in the terms a refusal names, is the stack read back: one `script N` for each open
     * container, N being the sub-script of it being read. That is worked out when something is wrong rather than at
     * every node, because it is as long as the script is deep and building it each time would cost the square of
     * that.
     */
    private static function readScript(string $bytes, int &$offset): self
    {
        /**
         * @var list<array{
         *   kind: string, required: ?int, nodeIndefinite: bool, childForm: SequenceForm, childCount: ?int,
         *   children: list<self>
         * }> $stack
         */
        $stack = [];

        $context = static function () use (&$stack): string {
            $text = 'native script';

            foreach ($stack as $frame) {
                $text .= ' script '.count($frame['children']);
            }

            return $text;
        };

        $expand = true;
        $completed = null;

        while (true) {
            if ($expand) {
                $expand = false;
                $frame = self::readNode($bytes, $offset, $context);

                if ($frame instanceof self) {
                    $completed = $frame;

                    continue;
                }

                if (count($stack) >= self::MAX_DEPTH) {
                    throw self::tooDeep(null);
                }

                if (self::listIsDone($frame, $bytes, $offset)) {
                    $completed = self::closeNode($frame, $bytes, $offset, $context);

                    continue;
                }

                $stack[] = $frame;
                $expand = true;

                continue;
            }

            if ($stack === []) {
                return $completed;
            }

            $top = count($stack) - 1;
            $stack[$top]['children'][] = $completed;

            if (self::listIsDone($stack[$top], $bytes, $offset)) {
                $frame = array_pop($stack);
                $completed = self::closeNode($frame, $bytes, $offset, $context);

                continue;
            }

            $expand = true;
        }
    }

    /**
     * The next node in $bytes: a finished leaf, or the frame an open container is assembled in.
     *
     * The node array's own framing is read but not kept. Every encoder writes that array definite in length, so a
     * node framed any other way is one this package cannot reproduce, and fromCbor() catches it by comparing bytes.
     *
     * @param  Closure(): string  $context
     * @return self|array{
     *   kind: string, required: ?int, nodeIndefinite: bool, childForm: SequenceForm, childCount: ?int,
     *   children: list<self>
     * }
     */
    private static function readNode(string $bytes, int &$offset, Closure $context): self|array
    {
        $head = CborHead::read($bytes, $offset, $context);

        if ($head->major !== CborHead::MAJOR_ARRAY) {
            throw new DecodeException(sprintf('%s: expected an array, got %s.', $context(), $head->describe()));
        }

        $nodeIndefinite = $head->isIndefinite();
        $itemCount = $nodeIndefinite ? null : $head->length($context);

        if ($itemCount === 0) {
            throw new DecodeException($context().': a native script names a constructor.');
        }

        $constructorContext = static fn (): string => $context().' constructor';
        $constructor = CborHead::read($bytes, $offset, $constructorContext);

        if ($constructor->major !== CborHead::MAJOR_UNSIGNED_INTEGER || $constructor->isIndefinite()) {
            throw new DecodeException(sprintf(
                '%s: expected an unsigned integer, got %s.',
                $constructorContext(),
                $constructor->describe()
            ));
        }

        $tag = $constructor->length($constructorContext);
        $kind = array_search($tag, self::TAGS, true);

        if ($kind === false) {
            throw new DecodeException(sprintf('%s: no native script constructor %d.', $context(), $tag));
        }

        $expected = $kind === self::AT_LEAST ? 3 : 2;

        if ($itemCount !== null && $itemCount !== $expected) {
            throw new DecodeException(sprintf(
                '%s: a %s clause is %d items, got %d.',
                $context(),
                $kind,
                $expected,
                $itemCount
            ));
        }

        if ($kind === self::SIG) {
            $keyHash = self::readKeyHash($bytes, $offset, static fn (): string => $context().' key hash');

            return self::closeLeaf(self::sig(bin2hex($keyHash)), $nodeIndefinite, $bytes, $offset, $context);
        }

        if ($kind === self::BEFORE || $kind === self::AFTER) {
            // A CBOR unsigned integer stops at 2^64-1, and a bignum above it is a tag rather than an integer and is
            // refused here. Reading the value as a decimal string keeps the half of the range PHP cannot hold.
            $slot = self::readUnsigned($bytes, $offset, static fn (): string => $context().' slot');
            $leaf = $kind === self::BEFORE ? self::before($slot) : self::after($slot);

            return self::closeLeaf($leaf, $nodeIndefinite, $bytes, $offset, $context);
        }

        $required = null;

        if ($kind === self::AT_LEAST) {
            // The threshold is a signed integer in the CDDL, and a negative one is a script the ledger accepts.
            $required = self::readSigned($bytes, $offset, static fn (): string => $context().' threshold');
        }

        $listContext = static fn (): string => $context().' scripts';
        $listHead = CborHead::read($bytes, $offset, $listContext);

        if ($listHead->major !== CborHead::MAJOR_ARRAY) {
            throw new DecodeException(sprintf(
                '%s: expected an array, got %s.',
                $listContext(),
                $listHead->describe()
            ));
        }

        $indefinite = $listHead->isIndefinite();

        return [
            'kind' => $kind,
            'required' => $required,
            'nodeIndefinite' => $nodeIndefinite,
            'childForm' => $indefinite ? SequenceForm::indefinite() : SequenceForm::definite(),
            'childCount' => $indefinite ? null : $listHead->length($listContext),
            'children' => [],
        ];
    }

    /**
     * Whether every sub-script of an open container has been read, consuming the break byte that says so.
     *
     * @param  array{childCount: ?int, children: list<self>}  $frame
     */
    private static function listIsDone(array $frame, string $bytes, int &$offset): bool
    {
        if ($frame['childCount'] !== null) {
            return count($frame['children']) === $frame['childCount'];
        }

        if (! CborHead::atBreak($bytes, $offset)) {
            return false;
        }

        $offset++;

        return true;
    }

    /**
     * The container a finished frame describes.
     *
     * @param  array{kind: string, required: ?int, nodeIndefinite: bool, childForm: SequenceForm, children: list<self>}  $frame
     * @param  Closure(): string  $context
     */
    private static function closeNode(array $frame, string $bytes, int &$offset, Closure $context): self
    {
        if ($frame['nodeIndefinite']) {
            self::readBreak($bytes, $offset, $context);
        }

        if ($frame['kind'] === self::AT_LEAST) {
            $required = (int) $frame['required'];
            self::checkThreshold($required, count($frame['children']));

            return new self(
                self::AT_LEAST,
                $frame['children'],
                required: $required,
                childForm: $frame['childForm'],
            );
        }

        return new self($frame['kind'], $frame['children'], childForm: $frame['childForm']);
    }

    /**
     * A leaf, once the break byte of an indefinite length node array has been accounted for.
     *
     * @param  Closure(): string  $context
     */
    private static function closeLeaf(
        self $leaf,
        bool $nodeIndefinite,
        string $bytes,
        int &$offset,
        Closure $context,
    ): self {
        if ($nodeIndefinite) {
            self::readBreak($bytes, $offset, $context);
        }

        return $leaf;
    }

    /**
     * @param  string|Closure(): string  $context
     */
    private static function readBreak(string $bytes, int &$offset, string|Closure $context): void
    {
        if (! CborHead::atBreak($bytes, $offset)) {
            $named = $context instanceof Closure ? $context() : $context;

            throw new DecodeException($named.': expected the end of an indefinite length array.');
        }

        $offset++;
    }

    /**
     * @param  string|Closure(): string  $context
     */
    private static function readKeyHash(string $bytes, int &$offset, string|Closure $context): string
    {
        $head = CborHead::read($bytes, $offset, $context);

        if ($head->major !== CborHead::MAJOR_BYTE_STRING || $head->isIndefinite()) {
            throw new DecodeException(sprintf(
                '%s: expected a byte string, got %s.',
                $context instanceof Closure ? $context() : $context,
                $head->describe()
            ));
        }

        $length = $head->length($context);

        if ($length !== Blake2b::DIGEST_CREDENTIAL) {
            throw new DecodeException(sprintf(
                '%s: expected %d bytes, got %d.',
                $context instanceof Closure ? $context() : $context,
                Blake2b::DIGEST_CREDENTIAL,
                $length
            ));
        }

        if (strlen($bytes) - $offset < $length) {
            throw new DecodeException(sprintf(
                '%s: truncated input: %d byte(s) wanted at offset %d, %d available.',
                $context instanceof Closure ? $context() : $context,
                $length,
                $offset,
                max(strlen($bytes) - $offset, 0)
            ));
        }

        $value = substr($bytes, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * An unsigned integer, as the decimal string that carries all of a uint64.
     *
     * @param  string|Closure(): string  $context
     */
    private static function readUnsigned(string $bytes, int &$offset, string|Closure $context): string
    {
        $head = CborHead::read($bytes, $offset, $context);

        if ($head->major !== CborHead::MAJOR_UNSIGNED_INTEGER || $head->isIndefinite()) {
            throw new DecodeException(sprintf(
                '%s: expected an unsigned integer, got %s.',
                $context instanceof Closure ? $context() : $context,
                $head->describe()
            ));
        }

        return (string) $head->value();
    }

    /**
     * A signed integer, which is what the CDDL types an `atLeast` threshold as.
     *
     * @param  string|Closure(): string  $context
     */
    private static function readSigned(string $bytes, int &$offset, string|Closure $context): int
    {
        $head = CborHead::read($bytes, $offset, $context);
        $negative = $head->major === CborHead::MAJOR_NEGATIVE_INTEGER;

        if (($head->major !== CborHead::MAJOR_UNSIGNED_INTEGER && ! $negative) || $head->isIndefinite()) {
            throw new DecodeException(sprintf(
                '%s: expected an integer, got %s.',
                $context instanceof Closure ? $context() : $context,
                $head->describe()
            ));
        }

        $value = $negative ? BigInteger::of(-1)->minus($head->value()) : $head->value();

        if ($value->isGreaterThan(PHP_INT_MAX) || $value->isLessThan(PHP_INT_MIN)) {
            throw new DecodeException(sprintf(
                '%s: the value %s does not fit a PHP integer.',
                $context instanceof Closure ? $context() : $context,
                (string) $value
            ));
        }

        return $value->toInt();
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
        return $this->fold(static fn (self $node, array $children): array => match ($node->kind) {
            self::SIG => ['type' => self::SIG, 'keyHash' => $node->key?->hex()],
            self::BEFORE, self::AFTER => ['type' => $node->kind, 'slot' => self::slotForJson($node->slot)],
            self::AT_LEAST => [
                'type' => self::AT_LEAST,
                'required' => $node->required,
                'scripts' => $children,
            ],
            default => [
                'type' => $node->kind,
                'scripts' => $children,
            ],
        });
    }

    /**
     * The same, as the text of a script file.
     *
     * MAX_JSON_DEPTH says why this stops long before the CBOR form does. A script past it is refused rather than
     * written out short: json_encode answers a nesting it cannot reach with false, and handing that back as an empty
     * string would be a script file that parses as nothing and hashes to nothing.
     */
    public function toJson(): string
    {
        if ($this->depth > self::MAX_JSON_DEPTH) {
            throw new ScriptException(sprintf(
                'This script nests %d levels deep and the JSON form carries %d. Write it as CBOR instead.',
                $this->depth,
                self::MAX_JSON_DEPTH
            ));
        }

        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, self::JSON_NESTING);

        if ($json === false) {
            throw new ScriptException('This script cannot be written as JSON: '.json_last_error_msg().'.');
        }

        return $json;
    }

    /**
     * The script as CBOR values, for a caller that wants the structure rather than the bytes.
     *
     * The tree is assembled from the leaves up on a stack here, so building one costs no call stack. What comes back
     * is twice as deep as the script, because each node is an array holding a list. A script near MAX_DEPTH
     * therefore turns into eleven thousand levels of CBOR values, which is a tree PHP releases by recursing into it
     * however it was built. cbor() is the way to the bytes, and this is the way to something another CBOR-aware
     * caller can hold.
     */
    public function toCbor(): CborValue
    {
        return $this->fold(static function (self $node, array $children): CborValue {
            $tag = CborValue::unsigned(self::TAGS[$node->kind]);

            return match ($node->kind) {
                self::SIG => CborValue::sequence([$tag, CborValue::byteString((string) $node->key?->hash)]),
                self::BEFORE, self::AFTER => CborValue::sequence([
                    $tag,
                    CborInteger::of((string) $node->slot)->toCbor(),
                ]),
                self::AT_LEAST => CborValue::sequence([
                    $tag,
                    self::thresholdObject((int) $node->required),
                    ($node->childForm ?? SequenceForm::definite())->wrap($children),
                ]),
                default => CborValue::sequence([
                    $tag,
                    ($node->childForm ?? SequenceForm::definite())->wrap($children),
                ]),
            };
        });
    }

    /**
     * The serialized script, as raw bytes.
     *
     * Written straight out, on a stack of what is left to write, so the encoder has no ceiling of its own beyond the
     * one MAX_DEPTH puts on the script. A container writes its own head and then leaves its sub-scripts behind it in
     * order, with the break byte of an indefinite length list queued underneath them.
     */
    public function cbor(): string
    {
        $out = '';
        $stack = [$this];

        while ($stack !== []) {
            $item = array_pop($stack);

            if (is_string($item)) {
                $out .= $item;

                continue;
            }

            $tag = chr(self::TAGS[$item->kind]);

            if ($item->kind === self::SIG) {
                $hash = (string) $item->key?->hash;
                $out .= "\x82".$tag.CborHead::write(CborHead::MAJOR_BYTE_STRING, strlen($hash)).$hash;

                continue;
            }

            if ($item->kind === self::BEFORE || $item->kind === self::AFTER) {
                $out .= "\x82".$tag.CborCodec::encode(CborInteger::of((string) $item->slot)->toCbor());

                continue;
            }

            if ($item->kind === self::AT_LEAST) {
                $out .= "\x83".$tag.CborCodec::encode(self::thresholdObject((int) $item->required));
            } else {
                $out .= "\x82".$tag;
            }

            $form = $item->childForm ?? SequenceForm::definite();

            if ($form->indefinite) {
                $out .= chr(CborHead::MAJOR_ARRAY << 5 | CborHead::INDEFINITE);
                $stack[] = CborHead::BREAK;
            } else {
                $out .= CborHead::write(CborHead::MAJOR_ARRAY, count($item->scripts));
            }

            for ($i = count($item->scripts) - 1; $i >= 0; $i--) {
                $stack[] = $item->scripts[$i];
            }
        }

        return $out;
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
     * How many containers deep the longest path through this script runs, counting from nought at a leaf.
     *
     * A single `sig` is nought and an `all` around it is one, so this is the number the chain exercises measure: the
     * deepest script a node has accepted is 5,383, and MAX_DEPTH is where this package stops.
     */
    public function depth(): int
    {
        return $this->depth;
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
        $hashes = [];
        $stack = [$this];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node->kind === self::SIG) {
                $hashes[] = (string) $node->key?->hex();

                continue;
            }

            for ($i = count($node->scripts) - 1; $i >= 0; $i--) {
                $stack[] = $node->scripts[$i];
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
        return $this->fold(self::timeBound(...));
    }

    // -------------------------------------------------------------------- detail

    /**
     * Every node's value, worked out from the leaves up on a stack of half-finished containers.
     *
     * This is the shape every traversal in this class takes. `$combine` is handed a node and the values already
     * computed for its sub-scripts, in order, and gives back the value for that node. A frame is one container whose
     * sub-scripts are still being visited, so a script costs one array entry per level rather than one call frame,
     * and how deep PHP is willing to recurse is never what decides whether a script can be read.
     *
     * @template TValue
     *
     * @param  callable(self, list<TValue>): TValue  $combine
     * @return TValue
     */
    private function fold(callable $combine): mixed
    {
        /** @var list<array{node: self, values: list<TValue>}> $stack */
        $stack = [];
        $pending = $this;
        $value = null;

        while (true) {
            if ($pending !== null) {
                $node = $pending;
                $pending = null;

                if ($node->scripts === []) {
                    $value = $combine($node, []);

                    continue;
                }

                $stack[] = ['node' => $node, 'values' => []];
                $pending = $node->scripts[0];

                continue;
            }

            if ($stack === []) {
                return $value;
            }

            $top = count($stack) - 1;
            $stack[$top]['values'][] = $value;
            $next = count($stack[$top]['values']);

            if ($next < count($stack[$top]['node']->scripts)) {
                $pending = $stack[$top]['node']->scripts[$next];

                continue;
            }

            $frame = array_pop($stack);
            $value = $combine($frame['node'], $frame['values']);
        }
    }

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

    /**
     * A container read from the JSON form, with the one threshold the ecosystem refuses checked on the way.
     *
     * @param  list<self>  $children
     */
    private static function containerFromJson(string $kind, array $children, ?int $required, Framing $framing): self
    {
        if ($kind !== self::AT_LEAST) {
            return self::container($kind, $children, null, $framing);
        }

        self::checkThreshold((int) $required, count($children));

        return self::container(self::AT_LEAST, $children, $required, $framing);
    }

    /**
     * One node of the JSON form: the kind, a finished leaf when it is one, a threshold, and the sub-scripts to read.
     *
     * @param  array<string, mixed>  $json
     * @return array{string, ?self, ?int, list<mixed>}
     */
    private static function readJsonNode(array $json): array
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

            return [$kind, self::sig($keyHash), null, []];
        }

        if ($kind === self::BEFORE || $kind === self::AFTER) {
            $slot = $json['slot'] ?? null;

            if (! is_int($slot) && ! is_string($slot)) {
                throw new ScriptException(sprintf('A %s clause carries a slot number.', $kind));
            }

            return [$kind, $kind === self::BEFORE ? self::before($slot) : self::after($slot), null, []];
        }

        $children = $json['scripts'] ?? null;

        if (! is_array($children)) {
            throw new ScriptException(sprintf('A %s clause carries a list of scripts.', $kind));
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new ScriptException(sprintf('A %s clause holds scripts, not scalars.', $kind));
            }
        }

        $required = null;

        if ($kind === self::AT_LEAST) {
            $required = $json['required'] ?? null;

            if (! is_int($required)) {
                throw new ScriptException('An atLeast clause carries a required count.');
            }
        }

        return [$kind, null, $required, array_values($children)];
    }

    private function isFramedAs(Framing $framing): bool
    {
        $stack = [$this];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node->childForm === null) {
                continue;
            }

            if (! $framing->frames($node->childForm, count($node->scripts))) {
                return false;
            }

            foreach ($node->scripts as $script) {
                $stack[] = $script;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $signers
     */
    private function satisfied(array $signers, ?BigInteger $intervalStart, ?BigInteger $intervalEnd): bool
    {
        return $this->fold(
            static function (self $node, array $met) use ($signers, $intervalStart, $intervalEnd): bool {
                $count = count(array_filter($met));

                return match ($node->kind) {
                    self::SIG => in_array($node->key?->hex(), $signers, true),
                    self::AFTER => $intervalStart !== null
                        && $intervalStart->isGreaterThanOrEqualTo((string) $node->slot),
                    self::BEFORE => $intervalEnd !== null
                        && $intervalEnd->isLessThanOrEqualTo((string) $node->slot),
                    self::ALL => $count === count($met),
                    self::ANY => $count > 0,
                    default => $count >= (int) $node->required,
                };
            }
        );
    }

    /**
     * Whether a node is time bound, given the answer for each of its sub-scripts.
     *
     * @param  list<bool>  $children
     */
    private static function timeBound(self $node, array $children): bool
    {
        return match ($node->kind) {
            self::SIG => false,
            self::BEFORE, self::AFTER => true,
            // Every branch has to be satisfied, so one bounded branch binds all of them.
            self::ALL => in_array(true, $children, true),
            // The branches are alternatives, so an unbounded one escapes the bound.
            self::ANY => ! in_array(false, $children, true),
            // Bounded unless enough unbounded branches remain to meet the threshold.
            default => count(array_filter($children, static fn (bool $bound): bool => ! $bound))
                < (int) $node->required,
        };
    }

    /**
     * The signers, split by whether a time bound stands above them.
     *
     * Whether a sub-script is time bound is worked out once for every node and read back by identity, because the
     * walk below asks that question at each container and answering it by walking again would cost the square of the
     * size of the script.
     *
     * @return array{list<string>, list<string>}
     */
    private function partitionSigners(): array
    {
        /** @var array<int, bool> $bound */
        $bound = [];

        $this->fold(static function (self $node, array $children) use (&$bound): bool {
            $value = self::timeBound($node, $children);
            $bound[spl_object_id($node)] = $value;

            return $value;
        });

        $unbounded = [];
        $bounded = [];
        $stack = [[$this, false]];

        while ($stack !== []) {
            [$node, $isBounded] = array_pop($stack);

            if ($node->kind === self::SIG) {
                if ($isBounded) {
                    $bounded[] = (string) $node->key?->hex();
                } else {
                    $unbounded[] = (string) $node->key?->hex();
                }

                continue;
            }

            if ($node->kind === self::BEFORE || $node->kind === self::AFTER) {
                continue;
            }

            $childrenAreBounded = $isBounded
                || ($node->kind !== self::ANY && ($bound[spl_object_id($node)] ?? false));

            for ($i = count($node->scripts) - 1; $i >= 0; $i--) {
                $stack[] = [$node->scripts[$i], $childrenAreBounded];
            }
        }

        return [$unbounded, $bounded];
    }

    /**
     * The threshold, written as the signed integer the CDDL says it is.
     */
    private static function thresholdObject(int $required): CborValue
    {
        return $required < 0
            ? CborValue::negative($required)
            : CborValue::unsigned($required);
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
     * The refusal a script past MAX_DEPTH gets, which names the limit and what sets it.
     *
     * $depth is how deep the script is when that is known, which it is for one being assembled and is not for one
     * being read: the reader stops at the limit and never finds out how much further the bytes go, so it says the
     * script is deeper than the limit rather than naming a depth it did not measure.
     */
    private static function tooDeep(?int $depth): ScriptException
    {
        $nesting = $depth === null
            ? sprintf('A native script nests deeper than %d levels', self::MAX_DEPTH)
            : sprintf('A native script nests %d levels deep and this package reads %d', $depth, self::MAX_DEPTH);

        return new ScriptException(sprintf(
            '%s, which is the deepest a transaction could carry one: a transaction holds %d bytes, one level of '
            .'nesting costs at least %d of them, and every route a script takes to the chain is weighed against '
            .'that limit.',
            $nesting,
            self::MAX_TRANSACTION_BYTES,
            self::MIN_BYTES_PER_LEVEL
        ));
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
