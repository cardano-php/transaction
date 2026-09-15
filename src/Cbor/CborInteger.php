<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\DecodeException;
use CBOR\CBORObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Throwable;

/**
 * An integer that remembers the width it arrived in.
 *
 * CBOR can write the same number five ways, and the ledger hashes the bytes it was handed rather than a canonical
 * form of them. A model that kept only the number would re-encode 1000 as the shortest head that holds it, which is
 * usually right and occasionally not, and the transaction hash would move under a transaction nobody edited. The
 * additional information byte is kept alongside the value, and the payload is rebuilt from the two, so a number that
 * came in wider than it needed to goes back out that way.
 *
 * Values are carried as decimal strings. A token quantity is a ledger uint64 and PHP's signed integer stops short
 * of that, so reading one into an int is a request the caller makes deliberately through toInt().
 */
final class CborInteger
{
    private const UINT8_MAX = 255;

    private const UINT16_MAX = 65535;

    private const UINT32_MAX = 4294967295;

    private const UINT64_MAX = '18446744073709551615';

    private function __construct(
        public readonly string $value,
        public readonly bool $negative,
        private readonly int $additionalInformation,
    ) {}

    public static function fromCbor(CBORObject $object, string $context): self
    {
        if ($object instanceof UnsignedIntegerObject) {
            return new self($object->getValue(), false, $object->getAdditionalInformation());
        }

        if ($object instanceof NegativeIntegerObject) {
            return new self($object->getValue(), true, $object->getAdditionalInformation());
        }

        throw new DecodeException(sprintf('%s: expected an integer, got %s.', $context, Shape::describe($object)));
    }

    /**
     * A fresh unsigned integer, written at the narrowest head that holds it.
     *
     * Decoding keeps whatever width arrived, because the ledger hashed those bytes. Building is the other direction
     * and has no bytes to preserve, so it picks the shortest form, which is what every other builder emits and what
     * keeps a transaction inside maxTxSize.
     */
    public static function of(int|string $value): self
    {
        $text = is_int($value) ? (string) $value : trim($value);

        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $text) !== 1) {
            throw new DecodeException(sprintf(
                'A CBOR unsigned integer is a non-negative decimal integer, got: %s',
                $value === '' ? '(empty string)' : (string) $value
            ));
        }

        $number = BigInteger::of($text);

        $additionalInformation = match (true) {
            $number->isLessThanOrEqualTo(23) => $number->toInt(),
            $number->isLessThanOrEqualTo(self::UINT8_MAX) => 24,
            $number->isLessThanOrEqualTo(self::UINT16_MAX) => 25,
            $number->isLessThanOrEqualTo(self::UINT32_MAX) => 26,
            $number->isLessThanOrEqualTo(BigInteger::of(self::UINT64_MAX)) => 27,
            default => throw new DecodeException(sprintf(
                'A CBOR unsigned integer stops at %s, got %s.',
                self::UINT64_MAX,
                $text
            )),
        };

        return new self($text, false, $additionalInformation);
    }

    /**
     * The same value written at the widest head CBOR has, eight bytes.
     *
     * This exists for one purpose: sizing a field whose final value is not known yet. A fee written at full width
     * never grows when the number in it changes, which turns the fee fixed point from an iteration into a single
     * pass at the cost of up to seven bytes. FeeFixedPoint offers both and says which it used.
     */
    public static function widest(int|string $value): self
    {
        $narrow = self::of($value);

        return new self($narrow->value, false, 27);
    }

    public static function unsignedFromCbor(CBORObject $object, string $context): self
    {
        if (! $object instanceof UnsignedIntegerObject) {
            throw new DecodeException(sprintf(
                '%s: expected an unsigned integer, got %s.',
                $context,
                Shape::describe($object)
            ));
        }

        return new self($object->getValue(), false, $object->getAdditionalInformation());
    }

    public function toCbor(): CBORObject
    {
        $argument = $this->negative
            ? BigInteger::of(-1)->minus(BigInteger::of($this->value))
            : BigInteger::of($this->value);

        $payload = self::payload($this->additionalInformation, $argument);

        return $this->negative
            ? NegativeIntegerObject::createObjectForValue($this->additionalInformation, $payload)
            : UnsignedIntegerObject::createObjectForValue($this->additionalInformation, $payload);
    }

    public function toInt(): int
    {
        try {
            return BigInteger::of($this->value)->toInt();
        } catch (Throwable $e) {
            throw new DecodeException(
                sprintf('The value %s does not fit a PHP integer.', $this->value),
                0,
                $e
            );
        }
    }

    public function equalsInt(int $other): bool
    {
        return BigInteger::of($this->value)->isEqualTo($other);
    }

    private static function payload(int $additionalInformation, BigInteger $argument): ?string
    {
        if ($additionalInformation <= 23) {
            return null;
        }

        $width = match ($additionalInformation) {
            24 => 2,
            25 => 4,
            26 => 8,
            27 => 16,
            default => throw new DecodeException(sprintf(
                'Unsupported integer additional information %d.',
                $additionalInformation
            )),
        };

        $payload = hex2bin(str_pad($argument->toBase(16), $width, '0', STR_PAD_LEFT));
        if ($payload === false) {
            throw new DecodeException('Unable to rebuild the integer payload.');
        }

        return $payload;
    }
}
