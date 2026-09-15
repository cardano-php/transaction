<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use CBOR\ByteStringObject;
use CBOR\CBORObject;
use CBOR\IndefiniteLengthByteStringObject;
use CBOR\IndefiniteLengthListObject;
use CBOR\IndefiniteLengthMapObject;
use CBOR\IndefiniteLengthTextStringObject;
use CBOR\ListObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\OtherObject\FalseObject;
use CBOR\OtherObject\NullObject;
use CBOR\OtherObject\TrueObject;
use CBOR\Tag;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;

/**
 * Type assertions over decoded CBOR.
 *
 * Every read of a transaction goes through one of these rather than through an instanceof at the call site, so that a
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

    public static function bytes(CBORObject $object, string $context, ?int $length = null): string
    {
        if ($object instanceof IndefiniteLengthByteStringObject) {
            throw new DecodeException(sprintf('%s: expected a definite length byte string.', $context));
        }

        if (! $object instanceof ByteStringObject) {
            throw new DecodeException(sprintf(
                '%s: expected a byte string, got %s.',
                $context,
                self::describe($object)
            ));
        }

        $value = $object->getValue();
        if ($length !== null && strlen($value) !== $length) {
            throw new DecodeException(sprintf(
                '%s: expected %d bytes, got %d.',
                $context,
                $length,
                strlen($value)
            ));
        }

        return $value;
    }

    /**
     * A byte string whose length falls inside an inclusive range. Asset names are the case: nought to thirty-two.
     */
    public static function boundedBytes(CBORObject $object, string $context, int $min, int $max): string
    {
        $value = self::bytes($object, $context);
        if (strlen($value) < $min || strlen($value) > $max) {
            throw new DecodeException(sprintf(
                '%s: expected between %d and %d bytes, got %d.',
                $context,
                $min,
                $max,
                strlen($value)
            ));
        }

        return $value;
    }

    public static function isNull(CBORObject $object): bool
    {
        return $object instanceof NullObject;
    }

    public static function bool(CBORObject $object, string $context): bool
    {
        if ($object instanceof TrueObject) {
            return true;
        }

        if ($object instanceof FalseObject) {
            return false;
        }

        throw new DecodeException(sprintf(
            '%s: expected a boolean, got %s.',
            $context,
            self::describe($object)
        ));
    }

    /**
     * A short name for an object, for the message a refusal carries.
     */
    public static function describe(CBORObject $object): string
    {
        return match (true) {
            $object instanceof UnsignedIntegerObject => 'an unsigned integer',
            $object instanceof NegativeIntegerObject => 'a negative integer',
            $object instanceof ByteStringObject => 'a byte string',
            $object instanceof IndefiniteLengthByteStringObject => 'an indefinite length byte string',
            $object instanceof TextStringObject,
            $object instanceof IndefiniteLengthTextStringObject => 'a text string',
            $object instanceof ListObject => 'an array',
            $object instanceof IndefiniteLengthListObject => 'an indefinite length array',
            $object instanceof MapObject => 'a map',
            $object instanceof IndefiniteLengthMapObject => 'an indefinite length map',
            $object instanceof Tag => sprintf('a tagged item (tag head %d)', $object->getAdditionalInformation()),
            $object instanceof NullObject => 'null',
            $object instanceof TrueObject, $object instanceof FalseObject => 'a boolean',
            default => 'a '.$object::class,
        };
    }
}
