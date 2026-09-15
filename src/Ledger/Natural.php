<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Exception\ArithmeticException;

/**
 * A non-negative integer of unbounded width, carried as a decimal string and worked on with ext-bcmath.
 *
 * Nothing in ledger arithmetic may be done in a PHP float. A float carries fifty-three bits of mantissa, and a
 * lovelace amount passes that at about ninety million ADA, so the total supply does not fit one and neither does a
 * large token balance. The rounding is silent, which is the part that matters: a fee that comes out one lovelace low
 * is a transaction the node refuses, and a balance that comes out one unit high is a transaction the node refuses
 * for a different reason.
 *
 * PHP's signed integer stops at 2^63-1. A ledger token quantity is a uint64 and goes to 2^64-1, so there are real
 * quantities on chain that no PHP integer can hold; a sum of several of them is not bounded by uint64 at all. Every
 * value here is therefore a string, arithmetic never leaves bcmath, and reading one back into an int is something
 * the caller asks for by name through toInt(), which refuses rather than truncates.
 *
 * All operations run at scale zero. bcmath's default scale is process-global and settable by anyone, so it is passed
 * explicitly on every call rather than trusted.
 */
final class Natural
{
    /** 2^64-1: the largest quantity of one asset the ledger can write into a value. */
    public const UINT64_MAX = '18446744073709551615';

    /** 2^63-1: the largest value a PHP integer holds on any platform this runs on. */
    public const PHP_INT_MAX_TEXT = '9223372036854775807';

    private function __construct(public readonly string $value) {}

    public static function of(int|string $value): self
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new ArithmeticException(sprintf('%d is negative; this is a count, not a balance change.', $value));
            }

            return new self((string) $value);
        }

        $text = trim($value);

        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $text) !== 1) {
            throw new ArithmeticException(sprintf(
                'Expected a non-negative integer written in decimal with no leading zero, got: %s',
                $value === '' ? '(empty string)' : $value
            ));
        }

        return new self($text);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    /**
     * A quantity as the ledger can write it: nought through 2^64-1.
     *
     * Sums of quantities are not held to this, because a wallet may legitimately hold more of an asset across several
     * outputs than one output could carry. The bound belongs where a number is about to be written into a value, and
     * ValuePacker is what applies it there.
     */
    public static function quantity(int|string $value): self
    {
        $natural = self::of($value);

        if ($natural->isGreaterThan(self::of(self::UINT64_MAX))) {
            throw new ArithmeticException(sprintf(
                'A ledger asset quantity is at most %s, got %s.',
                self::UINT64_MAX,
                $natural->value
            ));
        }

        return $natural;
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->value, $other->value, 0));
    }

    /**
     * Subtraction that refuses to go below zero rather than wrapping or returning a negative.
     *
     * Every subtraction in this layer is a balance leaving a total. One that would go negative means the total was
     * never enough, and the caller has to be told that rather than handed a number that looks like an answer.
     */
    public function minus(self $other): self
    {
        if ($this->isLessThan($other)) {
            throw new ArithmeticException(sprintf(
                'Subtracting %s from %s would go below zero.',
                $other->value,
                $this->value
            ));
        }

        return new self(bcsub($this->value, $other->value, 0));
    }

    /**
     * Subtraction that reports the shortfall instead of raising.
     *
     * @return array{self, self} what is left, and what was missing; one of the two is always zero
     */
    public function minusReportingShortfall(self $other): array
    {
        if ($this->isLessThan($other)) {
            return [self::zero(), new self(bcsub($other->value, $this->value, 0))];
        }

        return [new self(bcsub($this->value, $other->value, 0)), self::zero()];
    }

    public function times(int|string|self $other): self
    {
        $factor = $other instanceof self ? $other : self::of($other);

        return new self(bcmul($this->value, $factor->value, 0));
    }

    /**
     * Division rounding up, which is what a cost per byte has to do: the ledger never charges a fraction of a
     * lovelace and never forgives one either.
     */
    public function dividedByRoundingUp(int|string|self $other): self
    {
        $divisor = $other instanceof self ? $other : self::of($other);

        if ($divisor->isZero()) {
            throw new ArithmeticException('Division by zero.');
        }

        $quotient = bcdiv($this->value, $divisor->value, 0);

        return bccomp(bcmul($quotient, $divisor->value, 0), $this->value, 0) === 0
            ? new self($quotient)
            : new self(bcadd($quotient, '1', 0));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, 0);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function fitsInPhpInt(): bool
    {
        return bccomp($this->value, self::PHP_INT_MAX_TEXT, 0) <= 0;
    }

    /**
     * The value as a PHP integer, or a refusal.
     *
     * The refusal is the point. A quantity above 2^63-1 is a quantity the chain can hold and this platform cannot,
     * and silently handing back PHP_INT_MAX, or a float, or a wrapped negative, would put a wrong balance into a
     * transaction that then gets signed.
     */
    public function toInt(): int
    {
        if (! $this->fitsInPhpInt()) {
            throw new ArithmeticException(sprintf(
                '%s is above %s and does not fit a PHP integer.',
                $this->value,
                self::PHP_INT_MAX_TEXT
            ));
        }

        return (int) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
