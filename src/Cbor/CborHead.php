<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\DecodeException;
use Closure;

/**
 * One CBOR head, read out of a byte string without building anything.
 *
 * The CBOR library decodes a whole item at once and does it by recursion, so the deepest structure it will accept is
 * fixed by how much call stack it is willing to spend. That is the right trade for a transaction, whose shape is
 * flat and whose nesting is bounded by the era's grammar. It is the wrong trade for a native script, which nests
 * without limit and which the chain carries thousands of levels deep.
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
        private readonly ?string $argument,
    ) {}

    /**
     * The head at $offset, with $offset advanced past it.
     *
     * The three additional information values CBOR reserves for future use are refused here rather than carried
     * forward, because there is nothing a caller could do with one.
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
