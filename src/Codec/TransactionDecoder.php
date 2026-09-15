<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Codec;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Primitives\Transaction;

/**
 * The entry point of the read path: bytes in, a transaction out, or a DecodeException naming what was wrong.
 *
 * Nothing here keeps a slice of the input. Whatever comes back re-encodes itself from the model it decoded into, so
 * the hash the model computes is a statement about the decoder rather than about the bytes it was handed.
 */
final class TransactionDecoder
{
    private function __construct() {}

    public static function decode(string $bytes): Transaction
    {
        return Transaction::fromCbor(CborCodec::decode($bytes));
    }

    public static function decodeHex(string $hex): Transaction
    {
        $bytes = @hex2bin(trim($hex));

        if ($bytes === false) {
            throw new DecodeException('The input is not valid hexadecimal.');
        }

        return self::decode($bytes);
    }
}
