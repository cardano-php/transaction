<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;

/**
 * Type assertions over decoded CBOR.
 *
 * Every read of a transaction goes through one of these rather than through a type test at the call site, so that a
 * value in the wrong position is refused in one place and with one kind of message. The ledger accepted every
 * transaction the corpus was fetched by, which means the corpus cannot show whether the decoder would have refused
 * something else. These assertions, and the negative fixtures that exercise them, are the only thing that can.
 *
 * @internal This is the package's own reading machinery: the assertions every decoder in here makes before it trusts a
 * decoded value. It is named by no public signature and is not part of what the package promises to keep
 * working.
 */
final class Shape
{
    private function __construct() {}

    public static function bytes(CborValue $value, string $context, ?int $length = null): string
    {
        if ($value->isIndefiniteByteString()) {
            throw new DecodeException(sprintf('%s: expected a definite length byte string.', $context));
        }

        if (! $value->isByteString()) {
            throw new DecodeException(sprintf(
                '%s: expected a byte string, got %s.',
                $context,
                self::describe($value)
            ));
        }

        $payload = $value->stringValue();
        if ($length !== null && strlen($payload) !== $length) {
            throw new DecodeException(sprintf(
                '%s: expected %d bytes, got %d.',
                $context,
                $length,
                strlen($payload)
            ));
        }

        return $payload;
    }

    /**
     * A byte string whose length falls inside an inclusive range. Asset names are the case: nought to thirty-two.
     */
    public static function boundedBytes(CborValue $value, string $context, int $min, int $max): string
    {
        $payload = self::bytes($value, $context);
        if (strlen($payload) < $min || strlen($payload) > $max) {
            throw new DecodeException(sprintf(
                '%s: expected between %d and %d bytes, got %d.',
                $context,
                $min,
                $max,
                strlen($payload)
            ));
        }

        return $payload;
    }

    public static function isNull(CborValue $value): bool
    {
        return $value->isNull();
    }

    public static function bool(CborValue $value, string $context): bool
    {
        if ($value->isTrue()) {
            return true;
        }

        if ($value->isFalse()) {
            return false;
        }

        throw new DecodeException(sprintf(
            '%s: expected a boolean, got %s.',
            $context,
            self::describe($value)
        ));
    }

    /**
     * A short name for a value, for the message a refusal carries.
     */
    public static function describe(CborValue $value): string
    {
        return $value->describe();
    }
}
