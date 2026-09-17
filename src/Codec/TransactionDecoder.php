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
 * the only thing that can make the hash a statement about the bytes rather than about the decoder is checking that
 * the model writes those bytes back. That check is here, and it is the last thing a decode does.
 *
 * Every field this package models is rebuilt at the head it arrived in, so the check passes for everything the chain
 * writes and for the encodings it does not. What is left is a transaction written in a way this package cannot
 * reproduce, and the answer to one of those is a refusal rather than a hash that belongs to a different transaction:
 * a caller who acts on a wrong hash has signed, submitted or reported the wrong thing, and nothing downstream can
 * tell that it happened.
 */
final class TransactionDecoder
{
    private function __construct() {}

    public static function decode(string $bytes): Transaction
    {
        $transaction = Transaction::fromCbor(CborCodec::decode($bytes));

        if ($transaction->encode() !== $bytes) {
            throw new DecodeException(
                'This transaction is written in a way this package cannot reproduce, so re-encoding it would change '
                .'its hash. Hash the bytes it arrived in instead.'
            );
        }

        return $transaction;
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
