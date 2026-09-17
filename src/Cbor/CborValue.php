<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\DecodeException;

/**
 * One decoded CBOR item, carrying the head it arrived in as well as what it held.
 *
 * CBOR writes the same value several ways and the ledger hashes the bytes it was handed rather than a canonical form
 * of them. An integer has five widths, a string or a container has five ways to state its length or its count, a
 * string and a container may be written without a length at all and closed with a break byte, a set may or may not
 * carry tag 258, and a tag number is an argument with the same five widths as any other. A model that kept only the
 * value would write every one of those back in the shortest form, which is usually what arrived and occasionally is
 * not, and the transaction hash would move under a transaction nobody edited.
 *
 * So the head is kept beside the content: the major type, the additional information, and the argument bytes that
 * followed them. An item decoded here and written back out reproduces the bytes it was read from, whatever form they
 * were in. An item built here has no arrived bytes to reproduce and takes the shortest head that holds it, which is
 * what cardano-cli and every other encoder in the ecosystem writes.
 *
 * Nothing in here walks a structure. A value is a node with its children beside it, and reading, writing, measuring
 * or comparing one is done by CborReader and CborWriter on a stack of their own, because a transaction carries
 * native scripts that nest thousands of levels deep and a recursion that deep is a dead process rather than
 * something to catch.
 */
final class CborValue
{
    /** The largest argument a CBOR head carries, which is 2^64-1. */
    private const MAXIMUM_ARGUMENT = '18446744073709551615';

    /** Additional information 20 through 23 of major type 7: false, true, null, undefined. */
    private const SIMPLE_FALSE = 20;

    private const SIMPLE_TRUE = 21;

    private const SIMPLE_NULL = 22;

    /** The tag the Conway era writes a set with. Optional, and part of the hashed bytes wherever it appears. */
    public const TAG_SET = 258;

    /**
     * @param  string  $payload  the content of a definite length string, and empty for everything else
     * @param  list<self>  $items  array items, the chunks of an indefinite length string, or the one item of a tag
     * @param  list<array{self, self}>  $entries  map entries, in the order they were written
     */
    private function __construct(
        public readonly int $major,
        public readonly int $additionalInformation,
        public readonly ?string $argument,
        private readonly string $payload = '',
        private readonly array $items = [],
        private readonly array $entries = [],
    ) {}

    // ------------------------------------------------------------------ building

    /**
     * An unsigned integer, written at the narrowest head that holds it.
     *
     * A value above 2^63-1 does not fit a PHP integer and is given as a decimal string.
     */
    public static function unsigned(int|string $value): self
    {
        $number = self::checkedArgument($value, 'A CBOR unsigned integer');
        [$additionalInformation, $argument] = CborHead::headFor($number);

        return new self(CborHead::MAJOR_UNSIGNED_INTEGER, $additionalInformation, $argument);
    }

    /**
     * A negative integer, named by the number it is rather than by the argument CBOR writes for it.
     *
     * Major type 1 encodes -1 minus the value, so -1 is the argument 0 and the smallest one byte head there is.
     */
    public static function negative(int|string $value): self
    {
        $number = BigInteger::of(is_int($value) ? (string) $value : trim($value));

        if ($number->isGreaterThanOrEqualTo(0)) {
            throw new DecodeException('A CBOR negative integer is below zero, got '.$number.'.');
        }

        $argumentValue = self::checkedArgument(
            (string) BigInteger::of(-1)->minus($number),
            'A CBOR negative integer'
        );
        [$additionalInformation, $argument] = CborHead::headFor($argumentValue);

        return new self(CborHead::MAJOR_NEGATIVE_INTEGER, $additionalInformation, $argument);
    }

    /**
     * An integer written at the head it arrived in, which is how a decoded one is put back together.
     */
    public static function integerAs(bool $negative, int $additionalInformation, ?string $argument): self
    {
        return new self(
            $negative ? CborHead::MAJOR_NEGATIVE_INTEGER : CborHead::MAJOR_UNSIGNED_INTEGER,
            $additionalInformation,
            $argument,
        );
    }

    /** A definite length byte string, its head written at the narrowest width that states its length. */
    public static function byteString(string $payload): self
    {
        return self::stringOf(CborHead::MAJOR_BYTE_STRING, $payload);
    }

    /** A definite length text string, the same way. */
    public static function textString(string $payload): self
    {
        return self::stringOf(CborHead::MAJOR_TEXT_STRING, $payload);
    }

    /**
     * A definite length string written at the head it arrived in.
     */
    public static function stringAs(int $major, int $additionalInformation, ?string $argument, string $payload): self
    {
        return new self($major, $additionalInformation, $argument, $payload);
    }

    /**
     * A string written with no length at all, as the chunks it was written in and a break byte after them.
     *
     * @param  list<self>  $chunks
     */
    public static function chunkedString(int $major, array $chunks): self
    {
        return new self($major, CborHead::INDEFINITE, null, '', $chunks);
    }

    /**
     * An array, definite length by default and indefinite when the caller asks for it.
     *
     * @param  list<self>  $items
     */
    public static function sequence(array $items, bool $indefinite = false): self
    {
        if ($indefinite) {
            return new self(CborHead::MAJOR_ARRAY, CborHead::INDEFINITE, null, '', array_values($items));
        }

        [$additionalInformation, $argument] = CborHead::headFor(count($items));

        return new self(CborHead::MAJOR_ARRAY, $additionalInformation, $argument, '', array_values($items));
    }

    /**
     * An array written at the head it arrived in, for a caller putting back what it took apart.
     *
     * The head of an array states one thing, how many items follow it, so reusing it is safe exactly while the item
     * count is the one it was read with. SequenceForm is what holds a head to this and what checks that.
     *
     * @param  list<self>  $items
     */
    public static function sequenceAs(int $additionalInformation, ?string $argument, array $items): self
    {
        return new self(CborHead::MAJOR_ARRAY, $additionalInformation, $argument, '', array_values($items));
    }

    /**
     * @param  list<array{self, self}>  $entries
     */
    public static function map(array $entries, bool $indefinite = false): self
    {
        if ($indefinite) {
            return new self(CborHead::MAJOR_MAP, CborHead::INDEFINITE, null, '', [], array_values($entries));
        }

        [$additionalInformation, $argument] = CborHead::headFor(count($entries));

        return new self(CborHead::MAJOR_MAP, $additionalInformation, $argument, '', [], array_values($entries));
    }

    /**
     * @param  list<array{self, self}>  $entries
     */
    public static function mapAs(int $additionalInformation, ?string $argument, array $entries): self
    {
        return new self(CborHead::MAJOR_MAP, $additionalInformation, $argument, '', [], array_values($entries));
    }

    /** A tagged item, its tag number written at the narrowest head that holds it. */
    public static function tagged(int $tagNumber, self $value): self
    {
        [$additionalInformation, $argument] = CborHead::headFor($tagNumber);

        return new self(CborHead::MAJOR_TAG, $additionalInformation, $argument, '', [$value]);
    }

    /** A tagged item whose tag number is written at the width it arrived in. */
    public static function taggedAs(int $additionalInformation, ?string $argument, self $value): self
    {
        return new self(CborHead::MAJOR_TAG, $additionalInformation, $argument, '', [$value]);
    }

    /**
     * A major type 7 item: a boolean, null, undefined, a simple value or a float, kept as the head that names it.
     */
    public static function simple(int $additionalInformation, ?string $argument): self
    {
        return new self(CborHead::MAJOR_SIMPLE, $additionalInformation, $argument);
    }

    public static function bool(bool $value): self
    {
        return self::simple($value ? self::SIMPLE_TRUE : self::SIMPLE_FALSE, null);
    }

    public static function null(): self
    {
        return self::simple(self::SIMPLE_NULL, null);
    }

    // -------------------------------------------------------------- what it is

    public function isUnsigned(): bool
    {
        return $this->major === CborHead::MAJOR_UNSIGNED_INTEGER;
    }

    public function isNegative(): bool
    {
        return $this->major === CborHead::MAJOR_NEGATIVE_INTEGER;
    }

    public function isInteger(): bool
    {
        return $this->isUnsigned() || $this->isNegative();
    }

    /** A byte string written with its length, which is the only framing the ledger's own fields ever use. */
    public function isByteString(): bool
    {
        return $this->major === CborHead::MAJOR_BYTE_STRING && ! $this->isIndefinite();
    }

    public function isIndefiniteByteString(): bool
    {
        return $this->major === CborHead::MAJOR_BYTE_STRING && $this->isIndefinite();
    }

    public function isTextString(): bool
    {
        return $this->major === CborHead::MAJOR_TEXT_STRING;
    }

    public function isSequence(): bool
    {
        return $this->major === CborHead::MAJOR_ARRAY;
    }

    public function isMap(): bool
    {
        return $this->major === CborHead::MAJOR_MAP;
    }

    public function isTag(): bool
    {
        return $this->major === CborHead::MAJOR_TAG;
    }

    public function isSimple(): bool
    {
        return $this->major === CborHead::MAJOR_SIMPLE;
    }

    /** Written with no length in its head, so it runs until a break byte. */
    public function isIndefinite(): bool
    {
        return $this->additionalInformation === CborHead::INDEFINITE;
    }

    public function isTrue(): bool
    {
        return $this->isSimple() && $this->additionalInformation === self::SIMPLE_TRUE;
    }

    public function isFalse(): bool
    {
        return $this->isSimple() && $this->additionalInformation === self::SIMPLE_FALSE;
    }

    public function isNull(): bool
    {
        return $this->isSimple() && $this->additionalInformation === self::SIMPLE_NULL;
    }

    // ------------------------------------------------------------ what it holds

    /**
     * The content of a string, whichever framing it was written in.
     *
     * An indefinite length string is the concatenation of its chunks, which is what RFC 8949 section 3.2.3 says it
     * stands for. Whether it was written that way is still recorded, because the bytes differ and the hash with them.
     */
    public function stringValue(): string
    {
        if (! $this->isIndefinite()) {
            return $this->payload;
        }

        $value = '';

        foreach ($this->items as $chunk) {
            $value .= $chunk->payload;
        }

        return $value;
    }

    /**
     * The items of an array, the chunks of an indefinite length string, or the one item a tag carries.
     *
     * @return list<self>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<array{self, self}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /** The item a tag is wrapped around. */
    public function taggedValue(): self
    {
        if (! $this->isTag() || $this->items === []) {
            throw new DecodeException('This is not a tagged item.');
        }

        return $this->items[0];
    }

    /**
     * The tag number, which is the argument of a major type 6 head and so runs to 2^64-1 in principle.
     *
     * Nothing the chain writes comes near that, so a number past what a PHP integer holds is refused here rather
     * than carried as a string nobody would compare against anything.
     */
    public function tagNumber(): int
    {
        if (! $this->isTag()) {
            throw new DecodeException('This is not a tagged item.');
        }

        $number = self::nativeArgument($this->additionalInformation, $this->argument);

        if ($number === null) {
            throw new DecodeException('Tag number '.$this->argumentValue().' does not fit a PHP integer.');
        }

        return $number;
    }

    /**
     * An integer as the decimal string it is, negative sign and all, because a uint64 outruns a PHP integer.
     *
     * Every integer in a transaction comes through here, so the argument is built with native arithmetic and
     * brick/math is reached for only in the eight byte range above PHP_INT_MAX, which is where native arithmetic
     * wraps into the sign bit and stops being an answer.
     */
    public function integerText(): string
    {
        if (! $this->isInteger()) {
            throw new DecodeException('This is not an integer.');
        }

        $argument = self::nativeArgument($this->additionalInformation, $this->argument);

        if ($argument !== null) {
            return $this->isNegative() ? (string) (-1 - $argument) : (string) $argument;
        }

        $wide = $this->argumentValue();

        return $this->isNegative()
            ? (string) BigInteger::of(-1)->minus($wide)
            : (string) $wide;
    }

    /** The head of this item, as the bytes it is written with. */
    public function head(): string
    {
        return chr(($this->major << 5 | $this->additionalInformation) & 0xFF).($this->argument ?? '');
    }

    /** The content of a definite length string, with no chunks to gather. */
    public function definitePayload(): string
    {
        return $this->payload;
    }

    /**
     * A short name for this item, for the message a refusal carries.
     */
    public function describe(): string
    {
        $indefinite = $this->isIndefinite();

        return match ($this->major) {
            CborHead::MAJOR_UNSIGNED_INTEGER => 'an unsigned integer',
            CborHead::MAJOR_NEGATIVE_INTEGER => 'a negative integer',
            CborHead::MAJOR_BYTE_STRING => $indefinite ? 'an indefinite length byte string' : 'a byte string',
            CborHead::MAJOR_TEXT_STRING => 'a text string',
            CborHead::MAJOR_ARRAY => $indefinite ? 'an indefinite length array' : 'an array',
            CborHead::MAJOR_MAP => $indefinite ? 'an indefinite length map' : 'a map',
            CborHead::MAJOR_TAG => sprintf('a tagged item (tag head %d)', $this->additionalInformation),
            default => match (true) {
                $this->isNull() => 'null',
                $this->isTrue(), $this->isFalse() => 'a boolean',
                default => 'a simple value',
            },
        };
    }

    // -------------------------------------------------------------------- detail

    private static function stringOf(int $major, string $payload): self
    {
        [$additionalInformation, $argument] = CborHead::headFor(strlen($payload));

        return new self($major, $additionalInformation, $argument, $payload);
    }

    /**
     * The argument this head carries as a PHP integer, and null when it does not fit one.
     *
     * A CBOR argument is unsigned, so a negative result cannot be one: it says the argument sits above PHP_INT_MAX,
     * which only the eighth byte of an eight byte argument can put it.
     */
    private static function nativeArgument(int $additionalInformation, ?string $argument): ?int
    {
        if ($argument === null) {
            return $additionalInformation;
        }

        $length = strlen($argument);

        if ($length > 8) {
            return null;
        }

        $value = 0;

        for ($index = 0; $index < $length; $index++) {
            $value = ($value << 8) | ord($argument[$index]);
        }

        return $value >= 0 ? $value : null;
    }

    /**
     * The argument this head carries, as the number it is.
     */
    private function argumentValue(): BigInteger
    {
        if ($this->argument === null) {
            return BigInteger::of($this->additionalInformation);
        }

        return BigInteger::fromBase(bin2hex($this->argument), 16);
    }

    private static function checkedArgument(int|string $value, string $what): BigInteger
    {
        $text = is_int($value) ? (string) $value : trim($value);

        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $text) !== 1) {
            throw new DecodeException(sprintf(
                '%s is a non-negative decimal integer, got: %s',
                $what,
                $text === '' ? '(empty string)' : $text
            ));
        }

        $number = BigInteger::of($text);

        if ($number->isGreaterThan(BigInteger::of(self::MAXIMUM_ARGUMENT))) {
            throw new DecodeException(sprintf('%s stops at %s, got %s.', $what, self::MAXIMUM_ARGUMENT, $text));
        }

        return $number;
    }
}
