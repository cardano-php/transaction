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
 * Two things are asked of the input beyond its being well formed CBOR. It has to be exactly one item with nothing
 * after it, because a decoder that reads the first item and stops accepts any number of bytes appended to a
 * transaction. And it has to nest no deeper than MAX_DEPTH, which is a refusal a caller can catch rather than a
 * recursion a process cannot unwind.
 *
 * Everything about how a value was written is kept: the width of every integer head, the width of every length and
 * count, whether a string, an array or a map stated its length or ran to a break byte, and the tag on a set. The
 * ledger hashes the bytes it was handed, so a decoder that re-normalised any of that would hand back a transaction
 * whose hash had moved under a transaction nobody edited.
 */
final class CborCodec
{
    /**
     * How deep this reads, which is as deep as a transaction could nest.
     *
     * The cheapest level of CBOR nesting is a one byte head, `81`, an array holding one thing. A document of N bytes
     * therefore cannot nest deeper than N levels, and a transaction is at most maxTxSize bytes, which is 16,384 on
     * mainnet, preprod and preview alike. Nothing that fits a transaction can reach this limit: the deepest structure
     * the chain has actually carried is a native script of 5,383 levels, and a script level costs two CBOR levels,
     * so that lands a little under eleven thousand.
     *
     * The limit is here rather than at whatever depth PHP gives out at, and that is the whole point of it. A tree of
     * objects is released by recursing into it, on whatever stack the process was given, so a structure read past
     * what the process can free would take the process with it when it was released, with nothing thrown and nothing
     * to catch. Stopping where the ledger stops is the deepest that can be both promised and survived.
     */
    public const MAX_DEPTH = 16384;

    private function __construct() {}

    public static function decode(string $bytes): CborValue
    {
        if ($bytes === '') {
            throw new DecodeException('Empty input.');
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
