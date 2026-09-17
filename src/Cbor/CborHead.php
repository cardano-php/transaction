<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\DecodeException;
use Closure;

/**
 * One CBOR head, read out of a byte string without building anything, and written back the same way.
 *
 * A decoder that reads a whole item at once and does it by recursion has its deepest structure fixed by how much
 * call stack it is willing to spend. That is the wrong trade for anything this package reads: a native script nests
 * without limit and the chain carries them thousands of levels deep, and a transaction carries those scripts.
 *
 * This is the piece a reader needs to walk those bytes itself: the initial byte split into its major type and
 * additional information, and the argument that follows it, with the offset advanced past both. What the item means
 * is the caller's business, and so is the stack it keeps while it works out the answer.
 *
 * @internal This is the package's own reading machinery. It is named by no public signature and is not part of what
 * the package promises to keep working.
 */
final class CborHead
{
    public const MAJOR_UNSIGNED_INTEGER = 0;

    public const MAJOR_NEGATIVE_INTEGER = 1;

    public const MAJOR_BYTE_STRING = 2;

    public const MAJOR_TEXT_STRING = 3;

    public const MAJOR_ARRAY = 4;

    public const MAJOR_MAP = 5;

    public const MAJOR_TAG = 6;

    public const MAJOR_SIMPLE = 7;

    /** The additional information that says "no argument follows, this item runs until a break". */
    public const INDEFINITE = 31;

    /** The byte that closes an indefinite length item: major type 7 with additional information 31. */
    public const BREAK = "\xff";

    private function __construct(
        public readonly int $major,
        public readonly int $additionalInformation,
        public readonly ?string $argument,
    ) {}

    /**
     * The head at $offset, with $offset advanced past it.
     *
     * The three additional information values CBOR reserves for future use are refused here rather than carried
     * forward, because there is nothing a caller could do with one.
     *
     * So are the two byte simple values below 32. RFC 8949 section 3.3 gives major type 7 with additional
     * information 24 the simple values 32 to 255, and says in as many words that a two byte sequence starting 0xf8
     * and continuing with a byte below 0x20 is not well formed. Those thirty-two values already have a one byte
     * head, and false, true, null and undefined are four of them, so the second spelling is a second encoding of
     * something that already has one. A decoder that took it would hand back a value that re-encodes to different
     * bytes or hashes to a different transaction depending on which spelling it chose to write.
     *
     * $context may be given as a closure, which is called only when something is wrong. A reader walking a deeply
     * nested structure names each position from the path that reached it, and building that name for every item it
     * reads costs more than reading the bytes does.
     *
     * @param  string|Closure(): string  $context
     */
    public static function read(string $bytes, int &$offset, string|Closure $context): self
    {
        if ($offset >= strlen($bytes)) {
            throw new DecodeException(sprintf(
                '%s: truncated input: 1 byte(s) wanted at offset %d, 0 available.',
                self::named($context),
                $offset
            ));
        }

        $initial = ord($bytes[$offset]);
        $offset++;

        $major = $initial >> 5;
        $additionalInformation = $initial & 0b00011111;

        if ($additionalInformation <= 23 || $additionalInformation === self::INDEFINITE) {
            return new self($major, $additionalInformation, null);
        }

        if ($additionalInformation >= 28) {
            throw new DecodeException(sprintf(
                '%s: additional information %d is reserved.',
                self::named($context),
                $additionalInformation
            ));
        }

        $width = match ($additionalInformation) {
            24 => 1,
            25 => 2,
            26 => 4,
            default => 8,
        };

        if (strlen($bytes) - $offset < $width) {
            throw new DecodeException(sprintf(
                '%s: truncated input: %d byte(s) wanted at offset %d, %d available.',
                self::named($context),
                $width,
                $offset,
                max(strlen($bytes) - $offset, 0)
            ));
        }

        $argument = substr($bytes, $offset, $width);
        $offset += $width;

        if ($major === self::MAJOR_SIMPLE && $additionalInformation === 24 && ord($argument) < 32) {
            throw new DecodeException(sprintf(
                '%s: the simple value %d is written in one byte, so the two byte form of it is not well formed.',
                self::named($context),
                ord($argument)
            ));
        }

        return new self($major, $additionalInformation, $argument);
    }

    /**
     * The name of the position a refusal is about, worked out only once there is a refusal to make.
     *
     * @param  string|Closure(): string  $context
     */
    private static function named(string|Closure $context): string
    {
        return $context instanceof Closure ? $context() : $context;
    }

    /** Whether the next byte at $offset closes an indefinite length item. */
    public static function atBreak(string $bytes, int $offset): bool
    {
        return $offset < strlen($bytes) && $bytes[$offset] === self::BREAK;
    }

    /** An item written without an argument, which runs until a break byte. */
    public function isIndefinite(): bool
    {
        return $this->additionalInformation === self::INDEFINITE;
    }

    public function isBreak(): bool
    {
        return $this->major === self::MAJOR_SIMPLE && $this->additionalInformation === self::INDEFINITE;
    }

    /**
     * The head's argument as the number it is, which for a uint64 runs past what a PHP integer holds.
     */
    public function value(): BigInteger
    {
        if ($this->argument === null) {
            return BigInteger::of($this->additionalInformation);
        }

        return BigInteger::fromBase(bin2hex($this->argument), 16);
    }

    /**
     * The head's argument as a count or a length, which has to fit a PHP integer to be usable as either.
     */
    /**
     * @param  string|Closure(): string  $context
     */
    public function length(string|Closure $context): int
    {
        $value = $this->value();

        if ($value->isGreaterThan(PHP_INT_MAX)) {
            throw new DecodeException(sprintf(
                '%s: the value %s does not fit a PHP integer.',
                self::named($context),
                (string) $value
            ));
        }

        return $value->toInt();
    }

    /** The largest argument each head width states: one, two, four and eight bytes. */
    private const WIDTH_BOUNDS = [24 => '255', 25 => '65535', 26 => '4294967295', 27 => '18446744073709551615'];

    /**
     * The head an item of $major carrying $argument is written with, at the narrowest width that holds it.
     *
     * RFC 8949 section 3 gives an argument five encodings and section 4.2 asks for the shortest that holds it. That
     * is what every encoder in the ecosystem writes, so it is what this package writes when it has no arrived bytes
     * to reproduce. The bounds are inclusive: 255 is the largest one byte argument, not the first two byte one.
     *
     * Which width holds a number is a fact about CBOR and not about the machine, so the bounds are the CBOR ones on
     * both branches. An argument given as a PHP integer is at most PHP_INT_MAX and cannot reach the four byte bound
     * on a 32-bit build, which is why that branch may compare against native integers; an argument given as a
     * BigInteger is whatever the caller has and is compared against the widths themselves.
     *
     * @return array{int, ?string} the additional information, and the bytes that follow it
     */
    public static function headFor(int|BigInteger $argument): array
    {
        if ($argument instanceof BigInteger) {
            return self::wideHeadFor($argument);
        }

        if ($argument < 0) {
            throw new DecodeException('A CBOR head argument is never negative, got '.$argument.'.');
        }

        return match (true) {
            $argument <= 23 => [$argument, null],
            $argument <= 0xFF => [24, chr($argument)],
            $argument <= 0xFFFF => [25, pack('n', $argument)],
            $argument <= 0xFFFFFFFF => [26, pack('N', $argument)],
            default => [27, pack('J', $argument)],
        };
    }

    /**
     * The same, for an argument that arrived as arbitrary precision arithmetic.
     *
     * Deciding the width by whether the number fits a PHP integer is the wrong question asked twice. On a 64-bit
     * build it happens to give the right answer, because everything that does not fit a PHP integer needs eight
     * bytes anyway. On a 32-bit build it does not: every argument from 2^31 to 2^32-1 is above PHP_INT_MAX and
     * below the four byte bound, so each one would be written at an eight byte head instead of the shortest one.
     * Those bytes are what a script hash and a transaction hash are taken over, so the same script built on two
     * builds would hash to two different things.
     *
     * The payload is built from the number's own hexadecimal rather than through pack(), because pack('J') is an
     * eight byte format on a four byte integer and there is nothing useful for it to do with an argument that
     * outruns the word size.
     *
     * @return array{int, ?string}
     */
    private static function wideHeadFor(BigInteger $argument): array
    {
        if ($argument->isNegative()) {
            throw new DecodeException('A CBOR head argument is never negative, got '.$argument.'.');
        }

        if ($argument->isLessThanOrEqualTo(23)) {
            return [$argument->toInt(), null];
        }

        foreach (self::WIDTH_BOUNDS as $additionalInformation => $bound) {
            if (! $argument->isLessThanOrEqualTo(BigInteger::of($bound))) {
                continue;
            }

            $width = 2 ** ($additionalInformation - 24);
            $payload = hex2bin(str_pad($argument->toBase(16), $width * 2, '0', STR_PAD_LEFT));

            if ($payload === false) {
                throw new DecodeException('Unable to write the argument '.$argument.'.');
            }

            return [$additionalInformation, $payload];
        }

        throw new DecodeException(sprintf(
            'A CBOR head argument stops at %s, got %s.',
            self::WIDTH_BOUNDS[27],
            $argument
        ));
    }

    /**
     * The whole head of an item of $major carrying $argument, as bytes: the initial byte and whatever follows it.
     */
    public static function write(int $major, int|BigInteger $argument): string
    {
        [$additionalInformation, $payload] = self::headFor($argument);

        return chr($major << 5 | $additionalInformation).($payload ?? '');
    }

    /**
     * A short name for the item this head opens, for the message a refusal carries.
     */
    public function describe(): string
    {
        if ($this->isBreak()) {
            return 'the end of an indefinite length item';
        }

        $indefinite = $this->isIndefinite();

        return match ($this->major) {
            self::MAJOR_UNSIGNED_INTEGER => 'an unsigned integer',
            self::MAJOR_NEGATIVE_INTEGER => 'a negative integer',
            self::MAJOR_BYTE_STRING => $indefinite ? 'an indefinite length byte string' : 'a byte string',
            self::MAJOR_TEXT_STRING => 'a text string',
            self::MAJOR_ARRAY => $indefinite ? 'an indefinite length array' : 'an array',
            self::MAJOR_MAP => $indefinite ? 'an indefinite length map' : 'a map',
            self::MAJOR_TAG => sprintf('a tagged item (tag head %d)', $this->additionalInformation),
            default => 'a simple value',
        };
    }
}
