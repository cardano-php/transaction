<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\ValueSize;
use Cardano\Transaction\Primitives\TransactionOutput;
use PHPUnit\Framework\TestCase;

/**
 * The three rules, checked against what the ledger actually accepted.
 *
 * Nothing in this file compares the arithmetic to a second opinion written alongside it. Every assertion is against
 * a number a node already decided: the coin a real output holds, the fee a real transaction paid, the size of a
 * real value. The chain enforced all three at the time, so one violation in ten thousand rows is not noise, it is
 * a formula that is wrong.
 */
class LedgerOracleTest extends TestCase
{
    /**
     * Assertion 1. For every real output, the computed minimum UTxO is at or below the coin it holds.
     */
    public function test_every_real_output_holds_at_least_its_computed_minimum(): void
    {
        $minimum = MinimumUtxo::under(LedgerFixtures::parameters());

        $outputs = 0;
        $tightest = null;
        $tightestAt = '';

        foreach (LedgerFixtures::transactions() as $transaction) {
            foreach ($transaction['outputs'] as $index => $bytes) {
                $output = TransactionOutput::fromCbor(CborCodec::decode($bytes), 'output');
                $required = $minimum->forOutput($output);
                $held = Natural::of($output->value->coin->value);

                $this->assertTrue(
                    $held->isAtLeast($required),
                    sprintf(
                        'Output %d of %s holds %s lovelace and the formula asks for %s. The ledger accepted it, so '
                        .'the formula is wrong.',
                        $index,
                        $transaction['tx'],
                        $held->value,
                        $required->value
                    )
                );

                $slack = $held->minus($required);
                if ($tightest === null || $slack->isLessThan($tightest)) {
                    $tightest = $slack;
                    $tightestAt = $transaction['tx'].' output '.$index;
                }

                $outputs++;
            }
        }

        $this->assertGreaterThan(
            5000,
            $outputs,
            'The corpus is meant to be several thousand outputs. A smaller one is a corpus that got truncated.'
        );

        // An output sitting exactly on the minimum is what says the bound is tight rather than merely satisfied. A
        // corpus where every output had a wide margin would pass a formula that was low by a thousand lovelace.
        $this->assertNotNull($tightest);
        $this->assertTrue(
            $tightest->isZero(),
            sprintf(
                'No output in the corpus sits exactly on its minimum; the closest was %s at %s, which leaves room '
                .'for the formula to be that much too low and still pass.',
                $tightest->value,
                $tightestAt
            )
        );
    }

    /**
     * Assertion 2. For every real transaction, the computed minimum fee is at or below the fee it paid.
     *
     * Measured over the whole transaction, witnesses included. The witness set is part of what the node was handed
     * and part of what it charged for, and a fee computed over the body alone comes out roughly a hundred bytes
     * short per signature.
     *
     * The size used is the one the chain recorded for the transaction, not the length of the CBOR a provider hands
     * back for it. Those differ, by exactly one byte, every time. See the test below.
     */
    public function test_every_real_transaction_paid_at_least_its_computed_minimum_fee(): void
    {
        $calculator = FeeCalculator::under(LedgerFixtures::parameters());

        $transactions = 0;
        $exact = 0;

        foreach (LedgerFixtures::transactions() as $transaction) {
            $required = $calculator->forSize($transaction['size']);
            $paid = Natural::of($transaction['fee']);

            $this->assertTrue(
                $paid->isAtLeast($required),
                sprintf(
                    '%s is %d bytes and paid %s lovelace; the formula asks for %s. The ledger accepted it, so the '
                    .'formula is wrong.',
                    $transaction['tx'],
                    $transaction['size'],
                    $paid->value,
                    $required->value
                )
            );

            if ($paid->equals($required)) {
                $exact++;
            }

            $transactions++;
        }

        $this->assertGreaterThan(2000, $transactions);

        // Most builders write the exact minimum, so the corpus is full of transactions paying it to the lovelace.
        // Without them the assertion above would pass on a fee formula missing a whole term, which is exactly the
        // mistake of costing the body instead of the transaction.
        $this->assertGreaterThan(
            100,
            $exact,
            'Almost no transaction in the corpus paid exactly the computed minimum, which leaves room for the '
            .'formula to be short by a term and still pass.'
        );
    }

    /**
     * The size a transaction is charged on is one byte less than the length of the CBOR a provider returns for it.
     *
     * This is measured, not assumed, and it holds for all 3773 transactions in the corpus without exception, across
     * every shape in it: with and without auxiliary data, with and without set tags on the inputs, from 266 bytes to
     * twenty thousand. The one byte is the header of the four-item array that wraps body, witnesses, validity flag
     * and auxiliary data; a transaction carrying 280 bytes of auxiliary data shows the same difference of one as a
     * transaction carrying none, which is what rules out the auxiliary slot as the cause.
     *
     * It matters because 175 of the 3773 paid a fee below what the CBOR length implies, and every one of them is
     * short by exactly one txFeePerByte. Computing a fee from the length of a provider's bytes, and believing the
     * answer, would mean rejecting real transactions as underfunded.
     *
     * What this build does with it is refuse to rely on it. The builder measures its own serialization and charges
     * on that, which is at most one byte over what the ledger asks and never under. Forty-four lovelace is not
     * worth the risk of the relationship turning out to be an artefact of one provider.
     */
    public function test_the_charged_size_is_one_byte_less_than_the_serialized_length(): void
    {
        $calculator = FeeCalculator::under(LedgerFixtures::parameters());

        $rows = 0;
        $belowIfMeasuredFromTheCbor = 0;

        foreach (LedgerFixtures::transactions() as $transaction) {
            $this->assertSame(
                $transaction['bytes'] - 1,
                $transaction['size'],
                sprintf(
                    '%s is %d bytes of CBOR and the chain recorded it at %d. The relationship this suite relies on '
                    .'has changed.',
                    $transaction['tx'],
                    $transaction['bytes'],
                    $transaction['size']
                )
            );

            // Charging on the full serialization is never below what the ledger asks, which is the property that
            // makes the builder's choice safe.
            $this->assertTrue(
                $calculator->forSize($transaction['bytes'])
                    ->isAtLeast($calculator->forSize($transaction['size'])),
                'Measuring the whole serialization has to come out at or above the charged size.'
            );

            if (Natural::of($transaction['fee'])->isLessThan($calculator->forSize($transaction['bytes']))) {
                $belowIfMeasuredFromTheCbor++;
            }

            $rows++;
        }

        $this->assertGreaterThan(2000, $rows);

        // If this ever reaches zero the difference has stopped mattering, and the paragraph above is stale.
        $this->assertGreaterThan(
            100,
            $belowIfMeasuredFromTheCbor,
            'No transaction in the corpus would look underfunded if the fee were computed from the CBOR length, so '
            .'the distinction this test documents no longer has consequences.'
        );
    }

    /**
     * Assertion 3. Every real output's serialized value is at or below maxValueSize.
     */
    public function test_every_real_value_is_within_max_value_size(): void
    {
        $parameters = LedgerFixtures::parameters();
        $sizes = ValueSize::under($parameters);

        $largest = 0;
        $largestAt = '';
        $outputs = 0;

        foreach (LedgerFixtures::transactions() as $transaction) {
            foreach ($transaction['outputs'] as $index => $bytes) {
                $output = TransactionOutput::fromCbor(CborCodec::decode($bytes), 'output');

                $this->assertTrue(
                    $sizes->outputFits($output),
                    sprintf(
                        'Output %d of %s carries a value of %d bytes and maxValueSize is %s.',
                        $index,
                        $transaction['tx'],
                        ValueSize::ofOutput($output),
                        $parameters->maxValueSize->value
                    )
                );

                $size = ValueSize::ofOutput($output);
                if ($size > $largest) {
                    $largest = $size;
                    $largestAt = $transaction['tx'].' output '.$index;
                }

                $outputs++;
            }
        }

        $this->assertGreaterThan(5000, $outputs);

        // The corpus holds a value of exactly maxValueSize. That is what settles whether the rule is "at most" or
        // "less than": a check written as a strict inequality would refuse an output mainnet accepted, and only a
        // real one sitting on the line can tell the two apart.
        $this->assertSame(
            $parameters->maxValueSize->toInt(),
            $largest,
            sprintf(
                'The largest value in the corpus is %d bytes at %s, and maxValueSize is %s. The limit is only shown '
                .'to be inclusive by a value that reaches it.',
                $largest,
                $largestAt,
                $parameters->maxValueSize->value
            )
        );
    }

    /**
     * The corpus is the population, not a selection, and it has to carry enough asset-bearing outputs for the
     * minimum UTxO assertion to be about more than plain payments.
     *
     * A formula that ignored the multiasset map entirely would pass on ADA-only outputs, because those are small and
     * nearly always hold far more than the minimum. It is the outputs with hundreds of assets that hold the answer.
     */
    public function test_the_corpus_carries_enough_multi_asset_outputs_to_be_worth_running(): void
    {
        $withAssets = 0;
        $mostAssets = 0;

        foreach (LedgerFixtures::transactions() as $transaction) {
            foreach ($transaction['outputs'] as $bytes) {
                $output = TransactionOutput::fromCbor(CborCodec::decode($bytes), 'output');

                if ($output->value->hasAssets()) {
                    $withAssets++;
                    $mostAssets = max($mostAssets, $output->value->assetCount());
                }
            }
        }

        $this->assertGreaterThan(2000, $withAssets, 'Too few multi-asset outputs to test the formula on.');
        $this->assertGreaterThan(100, $mostAssets, 'No output in the corpus carries a large asset bundle.');
    }
}
