<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Exception\ArithmeticException;
use Cardano\Transaction\Exception\ParameterException;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\WitnessPlan;
use Cardano\Transaction\Primitives\VkeyWitness;
use PHPUnit\Framework\TestCase;

/**
 * The fee formula, its Conway tier, and the witness sizing the fixed point depends on.
 */
class FeeCalculatorTest extends TestCase
{
    private function parameters(): LedgerParameters
    {
        return LedgerFixtures::parameters();
    }

    public function test_the_fee_is_the_fixed_part_plus_the_size_times_the_per_byte_part(): void
    {
        $calculator = FeeCalculator::under($this->parameters());

        $this->assertSame((string) (155381 + 44 * 1000), $calculator->forSize(1000)->value);
        $this->assertSame((string) (155381 + 44 * 1), $calculator->forSize(1)->value);
    }

    public function test_it_refuses_a_size_that_could_not_be_a_transaction(): void
    {
        $this->expectException(ArithmeticException::class);

        FeeCalculator::under($this->parameters())->forSize(0);
    }

    /**
     * A vkey witness has no variable part, which is the whole reason a fee can be computed before a signature
     * exists. The claim is checked against the real corpus rather than asserted: every vkey witness in every
     * committed mainnet transaction encodes to exactly this many bytes.
     */
    public function test_every_real_vkey_witness_is_exactly_the_size_the_plan_assumes(): void
    {
        $seen = 0;

        foreach (TransactionFixtures::chainFixtures() as [$fixture]) {
            $transaction = \Cardano\Transaction\Codec\TransactionDecoder::decode(
                TransactionFixtures::bytes($fixture['file'])
            );

            foreach ($transaction->witnessSet->vkeyWitnesses() as $witness) {
                $this->assertSame(
                    WitnessPlan::VKEY_WITNESS_BYTES,
                    strlen($witness->encode()),
                    'A real witness is a different size from the one the fee is planned against.'
                );
                $seen++;
            }
        }

        $this->assertGreaterThan(10, $seen, 'Too few real witnesses to have checked anything.');
    }

    public function test_a_dummy_witness_is_the_same_size_as_a_real_one(): void
    {
        $dummies = WitnessPlan::forSignatures(3)->dummyWitnesses();

        $this->assertCount(3, $dummies);

        foreach ($dummies as $dummy) {
            $this->assertSame(WitnessPlan::VKEY_WITNESS_BYTES, strlen($dummy->encode()));
        }
    }

    /**
     * A dummy signature must not verify. If one ever reached a node the transaction would be refused rather than
     * accepted unsigned, and this is what says the dummies have that property.
     */
    public function test_a_dummy_signature_does_not_verify(): void
    {
        $dummy = VkeyWitness::of(str_repeat("\x00", 32), str_repeat("\x00", 64));

        $this->assertFalse($dummy->verifies(str_repeat("\x01", 32)));
    }

    public function test_the_vkey_field_counts_its_own_array_header(): void
    {
        $plan = WitnessPlan::forSignatures(2);

        $this->assertSame(202, $plan->signatureBytes());
        $this->assertSame(203, $plan->vkeyFieldBytes());

        $encoded = CborCodec::encode(
            \Cardano\Transaction\Cbor\SequenceForm::definite()->wrap(
                array_map(static fn (VkeyWitness $w) => $w->toCbor(), $plan->dummyWitnesses())
            )
        );

        $this->assertSame($plan->vkeyFieldBytes(), strlen($encoded));
    }

    public function test_a_plan_with_no_signatures_contributes_nothing(): void
    {
        $this->assertSame(0, WitnessPlan::forSignatures(0)->vkeyFieldBytes());
        $this->assertSame(0, WitnessPlan::forSignatures(0)->totalBytes());
    }

    /**
     * Conway's reference script charge, against figures worked from the ledger's own rule: the first 25600 bytes at
     * the base price, the next at 1.2 times it, accumulated exactly and floored once at the end.
     *
     * There is no chain oracle for this in the corpus. Whether an input carried a reference script cannot be read
     * from the transaction's own bytes, so these are worked figures and they are weaker evidence than the other
     * assertions in this suite.
     */
    public function test_the_reference_script_tier_is_exact(): void
    {
        $calculator = FeeCalculator::under($this->parameters());

        // Under one tier: size * price, with no multiplier involved.
        $this->assertSame((string) (1000 * 15), $calculator->referenceScriptFee(1000)->value);
        $this->assertSame((string) (25599 * 15), $calculator->referenceScriptFee(25599)->value);

        // Exactly one tier: the whole tier at the base price, and no part of the second.
        $this->assertSame((string) (25600 * 15), $calculator->referenceScriptFee(25600)->value);

        // One byte into the second tier: a whole tier at 15, then one byte at 18.
        $this->assertSame((string) (25600 * 15 + 18), $calculator->referenceScriptFee(25601)->value);

        // Two whole tiers and one byte: 15, then 18, then one byte at 21.6, which floors with the rest.
        $expected = (int) floor(25600 * 15 + 25600 * 18 + 1 * 21.6);
        $this->assertSame((string) $expected, $calculator->referenceScriptFee(51201)->value);

        $this->assertSame('0', $calculator->referenceScriptFee(0)->value);
    }

    /**
     * The accumulator is floored once, at the end, not tier by tier. Flooring per tier comes out low, and a fee that
     * is low by one lovelace is a transaction the node refuses.
     */
    public function test_the_tier_accumulator_is_floored_once_rather_than_per_tier(): void
    {
        $calculator = FeeCalculator::under($this->parameters());

        // Three whole tiers plus three bytes. Prices are 15, 18, 21.6 and 25.92; only the last is fractional, and
        // three bytes of it is 77.76, so the exact total ends .76 and floors down by that much, once.
        $exact = 25600 * 15 + 25600 * 18 + 25600 * 21.6 + 3 * 25.92;

        $this->assertSame((string) ((int) floor($exact)), $calculator->referenceScriptFee(76803)->value);
    }

    public function test_it_refuses_to_cost_reference_scripts_without_the_parameter_for_them(): void
    {
        $withoutTier = new LedgerParameters(
            txFeeFixed: 155381,
            txFeePerByte: 44,
            utxoCostPerByte: 4310,
            maxValueSize: 5000,
            maxTxSize: 16384,
        );

        $this->expectException(ParameterException::class);
        $this->expectExceptionMessage('minFeeRefScriptCostPerByte');

        FeeCalculator::under($withoutTier)->forSize(500, referenceScriptBytes: 100);
    }

    public function test_it_refuses_a_negative_reference_script_size(): void
    {
        $this->expectException(ArithmeticException::class);

        FeeCalculator::under($this->parameters())->referenceScriptFee(-1);
    }

    public function test_max_tx_size_is_inclusive(): void
    {
        $calculator = FeeCalculator::under($this->parameters());

        $this->assertTrue($calculator->fitsMaxTxSize(16384));
        $this->assertFalse($calculator->fitsMaxTxSize(16385));
    }
}
