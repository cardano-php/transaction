<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Brick\Math\BigInteger;

/**
 * What a slot number is, stated once.
 *
 * `slot` is `uint` in the ledger CDDL, so a slot is an integer from 0 to 2^64-1 and nothing else. PHP's integer is
 * signed and stops at 2^63-1, which is half of that, so the upper half of every slot range has to be carried as a
 * BigInteger or it cannot be carried at all.
 *
 * The bound is worth enforcing rather than assuming. A slot outside the range still encodes to CBOR, still hashes to
 * a real 28-byte script hash and still yields an address a wallet will pay, and only a transaction trying to spend
 * that address fails. It fails in the node's decoder rather than at script validation, so the error names the
 * transaction and not the script, and the funds are already in by then. A negative slot is CBOR major type 1 where
 * the grammar requires major type 0, and one past 2^64-1 needs a bignum, which is not a `uint` either; both were
 * submitted to preprod and both were refused with `DecoderErrorDeserialiseFailure`, at any magnitude.
 *
 * This says what a slot is and leaves the complaining to the caller, because a slot arrives in more than one place
 * and each of them already has a way of saying what it refuses and why.
 */
final class Slot
{
    /** 2^64-1, the largest slot the ledger can write. */
    public const MAX = '18446744073709551615';

    private function __construct() {}

    /**
     * The value as a slot, or null when it is not one.
     *
     * An integer is taken as it stands. A string has to be written in decimal with no sign and no leading zero,
     * which is the form a slot past 2^63-1 arrives in, because JSON hands PHP a float for a larger literal and a
     * float carries fifty-three bits of mantissa.
     */
    public static function parse(int|string|BigInteger $value): ?BigInteger
    {
        if (is_string($value)) {
            if (preg_match('/^(?:0|[1-9][0-9]*)$/', trim($value)) !== 1) {
                return null;
            }

            $value = trim($value);
        }

        $slot = BigInteger::of($value);

        if ($slot->isNegative() || $slot->isGreaterThan(self::MAX)) {
            return null;
        }

        return $slot;
    }

    /**
     * What to say about a value that is not a slot.
     *
     * A slot arrives in more than one place and each of them raises its own exception, so the message is written
     * here and the raising is left to them. One wording means a caller reading two of them learns one rule.
     */
    public static function complaint(int|string|BigInteger $value): string
    {
        return sprintf(
            'A slot is an integer from 0 to %s, written in decimal, got: %s',
            self::MAX,
            $value === '' ? '(empty string)' : $value
        );
    }
}
