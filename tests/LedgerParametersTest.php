<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\ParameterException;
use Cardano\Transaction\Ledger\LedgerParameters;
use PHPUnit\Framework\TestCase;

/**
 * The parameters this arithmetic reads, and the rules about what may be missing.
 *
 * They are handed in rather than fetched. This library does not know what a network is or which provider is
 * configured, and taking them as a value is what lets the arithmetic sit on a branch that does not carry the
 * provider layer and compose with it unchanged when both land.
 */
class LedgerParametersTest extends TestCase
{
    public function test_it_reads_a_real_koios_epoch_params_row(): void
    {
        $parameters = LedgerFixtures::parameters();

        $this->assertSame('155381', $parameters->txFeeFixed->value);
        $this->assertSame('44', $parameters->txFeePerByte->value);
        $this->assertSame('4310', $parameters->utxoCostPerByte->value);
        $this->assertSame('5000', $parameters->maxValueSize->value);
        $this->assertSame('16384', $parameters->maxTxSize->value);
        $this->assertSame('15', $parameters->minFeeRefScriptCostPerByte?->value);
    }

    /**
     * Koios reports two of these as strings and the rest as numbers, in the same object. Reading the string ones
     * through a float is how a large lovelace figure stops being exact, so they go through decimal strings.
     */
    public function test_the_koios_row_mixes_strings_and_numbers_and_both_read_the_same(): void
    {
        $row = LedgerFixtures::epochParamsRow();

        $this->assertIsString($row['coins_per_utxo_size']);
        $this->assertIsInt($row['min_fee_a']);

        $this->assertSame('4310', LedgerParameters::fromKoiosEpochParams($row)->utxoCostPerByte->value);
    }

    /**
     * The names are the whole of the difference between providers, and getting min_fee_a and min_fee_b the wrong way
     * round is silent: the transaction still builds and pays a fee wrong by four orders of magnitude.
     */
    public function test_the_koios_translation_puts_the_two_fee_parameters_the_right_way_round(): void
    {
        $parameters = LedgerParameters::fromKoiosEpochParams([
            'min_fee_a' => 44,
            'min_fee_b' => 155381,
            'coins_per_utxo_size' => '4310',
            'max_val_size' => 5000,
            'max_tx_size' => 16384,
        ]);

        $this->assertSame('44', $parameters->txFeePerByte->value, 'min_fee_a is the per byte part.');
        $this->assertSame('155381', $parameters->txFeeFixed->value, 'min_fee_b is the fixed part.');
    }

    public function test_a_parameter_the_arithmetic_reads_cannot_be_missing(): void
    {
        foreach (['txFeeFixed', 'txFeePerByte', 'utxoCostPerByte', 'maxValueSize', 'maxTxSize'] as $name) {
            $values = [
                'txFeeFixed' => 155381,
                'txFeePerByte' => 44,
                'utxoCostPerByte' => 4310,
                'maxValueSize' => 5000,
                'maxTxSize' => 16384,
            ];
            unset($values[$name]);

            try {
                LedgerParameters::fromArray($values);
                $this->fail($name.' was allowed to be missing.');
            } catch (ParameterException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    public function test_a_parameter_present_but_null_is_treated_as_missing(): void
    {
        $this->expectException(ParameterException::class);
        $this->expectExceptionMessage('utxoCostPerByte is missing');

        LedgerParameters::fromArray([
            'txFeeFixed' => 155381,
            'txFeePerByte' => 44,
            'utxoCostPerByte' => null,
            'maxValueSize' => 5000,
            'maxTxSize' => 16384,
        ]);
    }

    /**
     * A field nobody here has heard of is ignored. The parameter set grows at every hard fork, and a reader that
     * refused an unknown field would stop working the day the fork lands, over a field it would never have read.
     */
    public function test_an_unknown_parameter_is_ignored(): void
    {
        $parameters = LedgerParameters::fromArray([
            'txFeeFixed' => 155381,
            'txFeePerByte' => 44,
            'utxoCostPerByte' => 4310,
            'maxValueSize' => 5000,
            'maxTxSize' => 16384,
            'somethingTheNextForkAdds' => 'whatever this turns out to be',
        ]);

        $this->assertSame('44', $parameters->txFeePerByte->value);
    }

    /**
     * A zero fee is legitimate on a private network. A zero cost per byte would make every minimum come out at
     * nothing, and a zero size limit would make every transaction too large, and no network has ever meant either.
     */
    public function test_a_zero_fee_is_allowed_and_a_zero_cost_per_byte_is_not(): void
    {
        $free = LedgerParameters::fromArray([
            'txFeeFixed' => 0,
            'txFeePerByte' => 0,
            'utxoCostPerByte' => 4310,
            'maxValueSize' => 5000,
            'maxTxSize' => 16384,
        ]);

        $this->assertTrue($free->txFeeFixed->isZero());

        foreach (['utxoCostPerByte', 'maxValueSize', 'maxTxSize'] as $name) {
            $values = [
                'txFeeFixed' => 155381,
                'txFeePerByte' => 44,
                'utxoCostPerByte' => 4310,
                'maxValueSize' => 5000,
                'maxTxSize' => 16384,
            ];
            $values[$name] = 0;

            try {
                LedgerParameters::fromArray($values);
                $this->fail($name.' was allowed to be zero.');
            } catch (ParameterException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    public function test_a_parameter_that_is_not_a_number_is_refused_by_name(): void
    {
        $this->expectException(ParameterException::class);
        $this->expectExceptionMessage('txFeePerByte is not a non-negative integer');

        LedgerParameters::fromArray([
            'txFeeFixed' => 155381,
            'txFeePerByte' => 'unknown',
            'utxoCostPerByte' => 4310,
            'maxValueSize' => 5000,
            'maxTxSize' => 16384,
        ]);
    }

    /**
     * The reference script price is the one optional field, because a transaction using no reference scripts pays
     * nothing under that head and does not need the number. Asking for it when it is absent is a refusal rather than
     * a nought, because a nought there would be an under-quote for a transaction that does use them.
     */
    public function test_the_reference_script_price_is_optional_but_not_defaulted(): void
    {
        $parameters = LedgerParameters::fromArray([
            'txFeeFixed' => 155381,
            'txFeePerByte' => 44,
            'utxoCostPerByte' => 4310,
            'maxValueSize' => 5000,
            'maxTxSize' => 16384,
        ]);

        $this->assertNull($parameters->minFeeRefScriptCostPerByte);

        $this->expectException(ParameterException::class);
        $parameters->refScriptCostPerByte();
    }
}
