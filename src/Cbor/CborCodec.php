<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use Throwable;

/**
 * The CBOR layer: bytes to a CborValue and back, read and written on a stack rather than on PHP's.
 *
 * Three things are asked of the input beyond its being well formed CBOR. It has to be exactly one item with nothing
 * after it, because a decoder that reads the first item and stops accepts any number of bytes appended to a
 * transaction. It has to nest no deeper than MAX_DEPTH, which is a refusal a caller can catch rather than a
 * recursion a process cannot unwind. And it has to be no longer than MAX_INPUT_BYTES, because past some length the
 * answer stops being a refusal and becomes the allocator giving up, which is a fatal error rather than an exception
 * and is not something a caller can handle.
 *
 * Everything about how a value was written is kept: the width of every integer head, the width of every length and
 * count, whether a string, an array or a map stated its length or ran to a break byte, and the tag on a set. The
 * ledger hashes the bytes it was handed, so a decoder that re-normalised any of that would hand back a transaction
 * whose hash had moved under a transaction nobody edited.
 */
final class CborCodec
{
    /**
     * The largest transaction the ledger accepts, on mainnet, preprod and preview alike.
     *
     * Both limits below are measured against it. NativeScript::MAX_TRANSACTION_BYTES is the same number said from
     * the script side, and the depth cases assert that the two have not drifted apart.
     */
    private const MAX_TRANSACTION_BYTES = 16384;

    /**
     * How deep this reads, which is as deep as a transaction could nest.
     *
     * The cheapest level of CBOR nesting is a one byte head, `81`, an array holding one thing. A document of N bytes
     * therefore cannot nest deeper than N levels, and a transaction is at most maxTxSize bytes, which is 16,384 on
     * mainnet, preprod and preview alike. Nothing that fits a transaction can reach this limit: the deepest structure
     * the chain has actually carried is a native script of 5,383 levels, and a script level costs two CBOR levels,
     * so that lands a little under eleven thousand.
     *
     * The limit is here rather than at whatever depth PHP gives out at, so that how deep this reads is a number that
     * was chosen and a refusal that can be caught.
     *
     * What it does not do is make the whole of that depth free of the call stack. Reading and writing a tree do not
     * recurse; releasing one does, inside the engine's own reference counting, where no PHP code runs and nothing
     * can be caught. The cost is around 160 bytes of C stack a level on PHP 8.3 on 64-bit Linux: freeing a tree at
     * MAX_DEPTH wants about 2.6 MB, and a transaction carrying a native script at NativeScript::MAX_DEPTH, which is
     * 10,924 CBOR levels, wants about 1.8 MB. The usual 8 MB main thread stack covers both with room to spare, and a
     * SAPI, container or thread configured below that does not: the process ends on SIGSEGV when the tree goes out
     * of scope, with nothing thrown and nothing to catch. A caller running with a small stack should hold the
     * decoder to a depth it has measured on its own build rather than to this one.
     */
    public const MAX_DEPTH = 16384;

    /**
     * The longest input this reads, past which it refuses rather than allocating.
     *
     * Nesting is bounded above, and that bounds one of the two ways a document costs memory. The other is breadth:
     * an array of a million items nests one level deep and is a million objects, so a bound on depth says nothing
     * about it. A document of N bytes holds at most N items, because the cheapest item is a one byte head, and past
     * some N the answer to it is the allocator giving up rather than a refusal. That is a fatal error, not an
     * exception, and a caller cannot catch it or carry on.
     *
     * Everything that arrives from the chain is bounded by maxTxSize, which is 16,384 bytes: a transaction is at
     * most that, and a native script, a witness set or an output is a piece of one. The limit here is four times
     * that rather than exactly that, because the deepest script this package promises to read is by construction a
     * little larger than what fits a transaction, and the framing around it larger again. Nothing under this limit
     * is thereby a transaction; what is over it is refused before anything is allocated for it.
     */
    public const MAX_INPUT_BYTES = 4 * self::MAX_TRANSACTION_BYTES;

    private function __construct() {}

    public static function decode(string $bytes): CborValue
    {
        if ($bytes === '') {
            throw new DecodeException('Empty input.');
        }

        if (strlen($bytes) > self::MAX_INPUT_BYTES) {
            throw new DecodeException(sprintf(
                'The input is %d bytes and this decoder reads at most %d, which is four times the %d bytes a '
                .'transaction can be. Nothing that fits a transaction comes near it.',
                strlen($bytes),
                self::MAX_INPUT_BYTES,
                self::MAX_TRANSACTION_BYTES
            ));
        }

        $offset = 0;

        try {
            $value = CborReader::read($bytes, $offset, self::MAX_DEPTH);
        } catch (DecodeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DecodeException('Malformed CBOR: '.$e->getMessage(), 0, $e);
        }

        $remaining = strlen($bytes) - $offset;

        if ($remaining !== 0) {
            throw new DecodeException(sprintf(
                'Malformed CBOR: %d byte(s) follow the top level item.',
                $remaining
            ));
        }

        return $value;
    }

    public static function encode(CborValue $value): string
    {
        return CborWriter::write($value);
    }
}
