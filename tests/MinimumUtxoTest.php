<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\ArithmeticException;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use PHPUnit\Framework\TestCase;

/**
 * The minimum UTxO formula, from the other direction.
 *
 * LedgerOracleTest proves the formula is never above what the chain accepted. That is a one-sided bound, and on its
 * own a formula returning one lovelace would satisfy it. This file is the other side: the figures are pinned for
 * outputs whose size is known, and the fixed point is held to the property that makes it a fixed point.
 */
class MinimumUtxoTest extends TestCase
{
    private function parameters(): LedgerParameters
    {
        return LedgerFixtures::parameters();
    }

    private function address(): string
    {
        // A mainnet base address: header byte 0x01, then two 28 byte credentials.
        return "\x01".str_repeat("\x11", 28).str_repeat("\x22", 28);
    }

    public function test_the_formula_is_the_overhead_plus_the_serialized_size_times_the_cost_per_byte(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());
        $output = TransactionOutput::create($this->address(), Value::lovelace('1000000'));

        $size = strlen($output->encode());

        $this->assertSame(
            (string) ((160 + $size) * 4310),
            $minimum->forOutput($output)->value,
            'The formula is (160 + serialized size) * utxoCostPerByte and nothing else.'
        );
    }

    /**
     * The overhead is a protocol constant and 160 is its value. Stated as its own assertion because it is the one
     * number in the formula that cannot be read from a parameter set, so nothing else would catch it changing.
     */
    public function test_the_entry_overhead_is_one_hundred_and_sixty_bytes(): void
    {
        $this->assertSame(160, MinimumUtxo::ENTRY_OVERHEAD_BYTES);
    }

    /**
     * The fixed point's defining property. An output funded to the computed minimum, measured again as it now
     * stands, needs no more than it holds. A single-pass calculation fails this whenever raising the coin widens
     * the integer that holds it.
     */
    public function test_an_output_funded_to_the_minimum_satisfies_the_minimum_when_measured_again(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());

        foreach ([0, 1, 5, 40, 300, 60000, 70000, 4000000000, 45000000000000000] as $seed) {
            $funded = $minimum->fund($this->address(), Value::lovelace((string) $seed));

            $this->assertTrue(
                $minimum->isSatisfiedBy($funded),
                sprintf(
                    'An output funded from a seed of %d holds %s and re-measures at %s.',
                    $seed,
                    $funded->value->coin->value,
                    $minimum->forOutput($funded)->value
                )
            );
        }
    }

    /**
     * The case the fixed point exists for, made explicit.
     *
     * Computing the minimum against an output written with a coin of nought gives a number whose own encoding is
     * wider than the nought it replaced. Writing that number in and measuring again gives a larger number.
     */
    public function test_a_single_pass_calculation_would_come_out_short(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());

        $empty = TransactionOutput::create($this->address(), Value::lovelace('0'));
        $singlePass = $minimum->forOutput($empty);

        $settled = $minimum->forValue($this->address(), Value::lovelace('0'));

        $this->assertTrue(
            $settled->isGreaterThan($singlePass),
            'A coin of nought is one byte and the settled coin is five, so the single pass has to come out lower.'
        );

        $written = TransactionOutput::create($this->address(), Value::lovelace($singlePass->value));
        $this->assertFalse(
            $minimum->isSatisfiedBy($written),
            'The single pass figure, written into the output, does not satisfy the rule it was derived from.'
        );
    }

    /**
     * An asset bundle raises the minimum, and by more than a count-based rule would suggest: a policy id is 28
     * bytes and an asset name up to 32, so the same number of assets under more policies costs more.
     */
    public function test_more_policies_cost_more_than_the_same_assets_under_one_policy(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());

        $onePolicy = Value::of('2000000', MultiAsset::of([
            AssetBundle::of(str_repeat("\xAA", 28), [['a', '1'], ['b', '1'], ['c', '1'], ['d', '1']]),
        ]));

        $fourPolicies = Value::of('2000000', MultiAsset::of([
            AssetBundle::of(str_repeat("\xAA", 28), [['a', '1']]),
            AssetBundle::of(str_repeat("\xBB", 28), [['b', '1']]),
            AssetBundle::of(str_repeat("\xCC", 28), [['c', '1']]),
            AssetBundle::of(str_repeat("\xDD", 28), [['d', '1']]),
        ]));

        $this->assertTrue(
            $minimum->forValue($this->address(), $fourPolicies)
                ->isGreaterThan($minimum->forValue($this->address(), $onePolicy)),
            'Four assets under four policies cost more than four under one, and a count cannot see that.'
        );
    }

    /**
     * A quantity past PHP's integer range is three bytes wider in CBOR than a small one, and the minimum has to
     * reflect that. It also has to be computable at all, which it would not be if the quantity went through an int.
     */
    public function test_a_quantity_past_the_php_integer_range_raises_the_minimum(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());
        $policy = str_repeat("\xAA", 28);

        $small = Value::of('2000000', MultiAsset::of([AssetBundle::of($policy, [['t', '1']])]));
        $huge = Value::of('2000000', MultiAsset::of([AssetBundle::of($policy, [['t', Natural::UINT64_MAX]])]));

        $this->assertTrue(
            $minimum->forValue($this->address(), $huge)
                ->isGreaterThan($minimum->forValue($this->address(), $small))
        );
    }

    public function test_it_refuses_a_size_that_could_not_be_an_output(): void
    {
        $this->expectException(ArithmeticException::class);

        MinimumUtxo::under($this->parameters())->forSerializedSize(0);
    }

    /**
     * Funding never lowers a coin. An output already holding more than the minimum is left exactly as it was,
     * because taking money out of an output somebody asked to send is not an arithmetic decision.
     */
    public function test_funding_leaves_an_output_that_already_meets_the_minimum_alone(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());
        $value = Value::lovelace('10000000');

        $funded = $minimum->fund($this->address(), $value);

        $this->assertSame('10000000', $funded->value->coin->value);
    }
}
