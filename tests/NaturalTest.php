<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\ArithmeticException;
use Cardano\Transaction\Ledger\Natural;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic type everything else is built on, held to the boundary it exists for.
 *
 * The corpus this branch runs against holds a real asset quantity of 9223372036847645859, which is 8929948 short of
 * PHP_INT_MAX. Mainnet traffic gets that close to the edge of a PHP integer on an ordinary afternoon, and the
 * ledger's own bound is 2^64, so the cases past the edge are constructed here rather than waited for.
 */
class NaturalTest extends TestCase
{
    public function test_it_reads_a_quantity_above_the_php_integer_range(): void
    {
        $quantity = Natural::of('18446744073709551615');

        $this->assertSame('18446744073709551615', $quantity->value);
        $this->assertFalse($quantity->fitsInPhpInt());
    }

    public function test_it_refuses_to_hand_back_a_quantity_that_does_not_fit_an_integer(): void
    {
        $this->expectException(ArithmeticException::class);
        $this->expectExceptionMessage('does not fit a PHP integer');

        Natural::of('18446744073709551615')->toInt();
    }

    public function test_the_largest_value_a_php_integer_holds_still_converts(): void
    {
        $this->assertSame(PHP_INT_MAX, Natural::of((string) PHP_INT_MAX)->toInt());
    }

    /**
     * One past PHP_INT_MAX is where a naive implementation stops being wrong quietly and starts being wrong loudly,
     * and the difference matters: a float would return 9.2233720368548E+18 here and compare equal to PHP_INT_MAX.
     */
    public function test_one_past_the_integer_range_is_still_exact(): void
    {
        $edge = Natural::of('9223372036854775808');

        $this->assertFalse($edge->fitsInPhpInt());
        $this->assertTrue($edge->isGreaterThan(Natural::of((string) PHP_INT_MAX)));
        $this->assertSame('1', $edge->minus(Natural::of((string) PHP_INT_MAX))->value);
    }

    public function test_sums_of_quantities_are_not_bounded_by_uint64(): void
    {
        $max = Natural::of(Natural::UINT64_MAX);
        $total = $max->plus($max)->plus($max);

        $this->assertSame('55340232221128654845', $total->value);
    }

    public function test_a_quantity_above_uint64_is_refused_as_a_ledger_quantity(): void
    {
        $this->expectException(ArithmeticException::class);
        $this->expectExceptionMessage('at most 18446744073709551615');

        Natural::quantity('18446744073709551616');
    }

    public function test_uint64_max_is_accepted_as_a_ledger_quantity(): void
    {
        $this->assertSame(Natural::UINT64_MAX, Natural::quantity(Natural::UINT64_MAX)->value);
    }

    public function test_subtraction_refuses_to_go_below_zero(): void
    {
        $this->expectException(ArithmeticException::class);
        $this->expectExceptionMessage('would go below zero');

        Natural::of(5)->minus(Natural::of(6));
    }

    public function test_it_reports_a_shortfall_rather_than_raising_when_asked_to(): void
    {
        [$left, $short] = Natural::of(5)->minusReportingShortfall(Natural::of(9));

        $this->assertSame('0', $left->value);
        $this->assertSame('4', $short->value);

        [$left, $short] = Natural::of(9)->minusReportingShortfall(Natural::of(5));

        $this->assertSame('4', $left->value);
        $this->assertSame('0', $short->value);
    }

    /**
     * Cardano's total supply is 45 billion ADA, which is 4.5e16 lovelace, and a float holds integers exactly only to
     * 2^53, about 9.007e15. Multiplying supply-sized numbers in a float goes wrong well before the ledger's bounds
     * do, which is why nothing here ever sees one.
     */
    public function test_multiplication_past_the_float_mantissa_stays_exact(): void
    {
        $supply = Natural::of('45000000000000000');

        $this->assertSame('2025000000000000000000000000000000', $supply->times($supply)->value);
    }

    public function test_division_rounds_up_because_a_cost_per_byte_never_forgives_a_fraction(): void
    {
        $this->assertSame('1', Natural::of(1)->dividedByRoundingUp(4310)->value);
        $this->assertSame('1', Natural::of(4310)->dividedByRoundingUp(4310)->value);
        $this->assertSame('2', Natural::of(4311)->dividedByRoundingUp(4310)->value);
    }

    public function test_it_refuses_a_value_that_is_not_a_plain_decimal_integer(): void
    {
        foreach (['', ' ', '1.0', '1e3', '-1', '0x10', '007', 'nine'] as $bad) {
            try {
                Natural::of($bad);
                $this->fail(sprintf('%s was accepted as a natural number.', var_export($bad, true)));
            } catch (ArithmeticException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * bcmath's scale is process-global and anyone can set it. Every call in Natural passes scale zero explicitly, so
     * a caller that has set it to eight elsewhere does not silently turn lovelace into a decimal.
     */
    public function test_it_is_unaffected_by_a_global_bcmath_scale(): void
    {
        $previous = bcscale(8);

        try {
            $this->assertSame('3', Natural::of(1)->plus(Natural::of(2))->value);
            $this->assertSame('6', Natural::of(2)->times(3)->value);
            $this->assertSame('1', Natural::of(3)->minus(Natural::of(2))->value);
            $this->assertSame('2', Natural::of(7)->dividedByRoundingUp(4)->value);
        } finally {
            bcscale($previous);
        }
    }
}
