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
 * the model writes those bytes back. Every field this package models is rebuilt at the head it arrived in, so that
 * check passes for everything the chain writes and for the encodings it does not; what is left is a transaction
 * written in a way this package cannot reproduce, and the answer to one of those is a refusal rather than a hash
 * that belongs to a different transaction.
 *
 * Which entry points carry that promise is worth being exact about, because more than one of them reaches a hash:
 *
 * - This class is the only one that takes bytes and answers with a transaction, and it is the only place the model
 *   is compared against the caller's own bytes rather than against a decoded value. That is the strongest form of
 *   the promise and it is what a caller holding bytes should use.
 * - Transaction::fromCbor and TransactionBody::fromCbor are public, take a decoded value, and each refuses a value
 *   it cannot write back out unchanged. So a caller who decodes the CBOR themselves, and a transaction this package
 *   assembles from parts, get the same promise about the value they handed in. TransactionBody is where it matters
 *   most, because the transaction hash is taken over the body alone.
 * - CborCodec::decode is public and carries the promise by construction rather than by checking: there is nothing
 *   in that layer that takes a transaction apart, and every item it reads holds the head it arrived in.
 *
 * What none of them promises is anything about a transaction after it has been changed. Signing replaces the
 * witness set, and the transaction that comes back is a different document from the one that went in; its body, and
 * therefore its hash, is untouched, which is the whole reason that swap is safe.
 */
final class TransactionDecoder
{
    private function __construct() {}

    public static function decode(string $bytes): Transaction
    {
        $transaction = Transaction::fromCbor(CborCodec::decode($bytes));

        // Transaction::fromCbor has already held the model to the value it was handed, and the CBOR layer writes
        // back exactly what it read, so between them the bytes below should already match. This says it about the
        // caller's bytes rather than about a value, which is one assumption fewer: it holds whatever the CBOR layer
        // does, and it is a string comparison.
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
