<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Selection\AssetId;
use Cardano\Transaction\Selection\CoinSelector;
use Cardano\Transaction\Selection\SelectionLimits;
use Cardano\Transaction\Selection\Utxo;
use Cardano\Transaction\Selection\ValueBag;
use PHPUnit\Framework\TestCase;

/**
 * Coin selection in both directions.
 *
 * The cases that matter are the ones where chasing lovelace alone gives the wrong answer, and where the assets are
 * the binding constraint. A selector that only looks at the coin passes a surprising number of tests, because
 * almost any selection of inputs covers a small ADA payment.
 */
class CoinSelectionTest extends TestCase
{
    private function address(int $seed = 0x11): string
    {
        return "\x01".str_repeat(chr($seed), 28).str_repeat("\x22", 28);
    }

    private function asset(int $seed, string $name = 'TOKEN'): AssetId
    {
        return AssetId::of(str_repeat(chr($seed), 28), $name);
    }

    /**
     * @param  list<array{AssetId, string}>  $assets
     */
    private function utxo(int $index, string $coin, array $assets = []): Utxo
    {
        return Utxo::of(
            str_pad((string) $index, 64, '0', STR_PAD_LEFT),
            0,
            $this->address(),
            ValueBag::of($coin, array_map(static fn (array $a): array => [$a[0], $a[1]], $assets))
        );
    }

    /**
     * A provider reports a policy id and an asset name in hex, so that is a spelling an asset has to be readable
     * from. The key is the two run together, which is the same string the chain's own subject identifier uses.
     */
    public function test_an_asset_reads_from_the_hex_a_provider_reports_it_in(): void
    {
        $policy = str_repeat('aa', 28);

        $this->assertSame(
            $policy.'544f4b454e',
            AssetId::fromHex($policy, '544f4b454e')->key()
        );

        $this->assertTrue(
            AssetId::fromHex($policy, '544f4b454e')->equals(AssetId::of(str_repeat("\xAA", 28), 'TOKEN'))
        );

        // An asset with no name is ordinary, and its key is the policy id alone.
        $this->assertSame($policy, AssetId::fromHex($policy)->key());
    }

    public function test_an_asset_refuses_a_policy_id_that_is_not_28_bytes(): void
    {
        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('28 bytes, got 27');

        AssetId::of(str_repeat("\xAA", 27), 'TOKEN');
    }

    public function test_an_asset_refuses_a_name_over_32_bytes(): void
    {
        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('at most 32 bytes, got 33');

        AssetId::of(str_repeat("\xAA", 28), str_repeat('n', 33));
    }

    public function test_it_covers_a_plain_lovelace_target(): void
    {
        $result = CoinSelector::unlimited()->select(
            [$this->utxo(1, '1000000'), $this->utxo(2, '5000000'), $this->utxo(3, '2000000')],
            ValueBag::ofCoin('4000000')
        );

        $this->assertTrue($result->selected->covers($result->target));
        $this->assertSame('1000000', $result->surplus->coin->value);
        $this->assertSame(1, $result->inputCount());
    }

    /**
     * The failure a value-only selector produces. There is plenty of lovelace here and the token sits in one output
     * that a largest-first ADA scan would never reach.
     */
    public function test_it_reaches_for_an_asset_a_lovelace_scan_would_never_pick_up(): void
    {
        $token = $this->asset(0xAA);

        $result = CoinSelector::unlimited()->select(
            [
                $this->utxo(1, '50000000'),
                $this->utxo(2, '40000000'),
                $this->utxo(3, '2000000', [[$token, '10']]),
            ],
            ValueBag::of('1000000', [[$token, Natural::of('5')]])
        );

        $this->assertContains(
            str_pad('3', 64, '0', STR_PAD_LEFT).'#0',
            $result->references(),
            'The only output holding the token has to be in the selection.'
        );
        $this->assertSame('5', $result->surplus->quantityOf($token)->value);
    }

    public function test_it_gathers_an_asset_from_several_outputs_when_one_is_not_enough(): void
    {
        $token = $this->asset(0xAA);

        $result = CoinSelector::unlimited()->select(
            [
                $this->utxo(1, '2000000', [[$token, '3']]),
                $this->utxo(2, '2000000', [[$token, '4']]),
                $this->utxo(3, '2000000', [[$token, '5']]),
            ],
            ValueBag::of('1000000', [[$token, Natural::of('8')]])
        );

        $this->assertTrue($result->selected->quantityOf($token)->isAtLeast(Natural::of('8')));
        // Largest holding first: 5 then 4 reaches 9, so two inputs, not three.
        $this->assertSame(2, $result->inputCount());
    }

    public function test_it_covers_several_assets_at_once(): void
    {
        $red = $this->asset(0xAA, 'RED');
        $blue = $this->asset(0xBB, 'BLUE');

        $result = CoinSelector::unlimited()->select(
            [
                $this->utxo(1, '2000000', [[$red, '10']]),
                $this->utxo(2, '2000000', [[$blue, '10']]),
                $this->utxo(3, '9000000'),
            ],
            ValueBag::of('3000000', [[$red, Natural::of('1')], [$blue, Natural::of('1')]])
        );

        $this->assertTrue($result->selected->covers($result->target));
        $this->assertSame('9', $result->surplus->quantityOf($red)->value);
        $this->assertSame('9', $result->surplus->quantityOf($blue)->value);
    }

    /**
     * Lovelace picked up by the asset pass counts. A selector that ran the two passes independently would take the
     * token output and then go looking for the whole lovelace target all over again.
     */
    public function test_lovelace_that_arrived_with_an_asset_counts_towards_the_lovelace_target(): void
    {
        $token = $this->asset(0xAA);

        $result = CoinSelector::unlimited()->select(
            [
                $this->utxo(1, '10000000', [[$token, '1']]),
                $this->utxo(2, '10000000'),
            ],
            ValueBag::of('5000000', [[$token, Natural::of('1')]])
        );

        $this->assertSame(1, $result->inputCount());
    }

    /**
     * ADA-only outputs are preferred when the shortfall is lovelace, because every asset dragged in has to be given
     * back as change, where it costs bytes, raises that output's minimum UTxO, and can force a second one.
     */
    public function test_the_lovelace_pass_prefers_outputs_carrying_no_assets(): void
    {
        $noise = $this->asset(0xCC, 'NOISE');

        $result = CoinSelector::unlimited()->select(
            [
                $this->utxo(1, '9000000', [[$noise, '100']]),
                $this->utxo(2, '8000000'),
            ],
            ValueBag::ofCoin('6000000')
        );

        $this->assertSame(1, $result->inputCount());
        $this->assertFalse($result->surplus->hasAssets(), 'The asset-free output was the cheaper one to spend.');
    }

    public function test_the_surplus_carries_every_token_the_inputs_held(): void
    {
        $paid = $this->asset(0xAA, 'PAID');
        $incidental = $this->asset(0xBB, 'ALONG');

        $result = CoinSelector::unlimited()->select(
            [$this->utxo(1, '5000000', [[$paid, '400'], [$incidental, '7']])],
            ValueBag::of('1000000', [[$paid, Natural::of('1')]])
        );

        $this->assertSame('399', $result->surplus->quantityOf($paid)->value);
        $this->assertSame('7', $result->surplus->quantityOf($incidental)->value);
        $this->assertSame('4000000', $result->surplus->coin->value);
    }

    /**
     * The message names what is missing. A wallet full of ADA and empty of the token being paid out is the case
     * where "insufficient funds" sends somebody to look at the wrong number.
     */
    public function test_a_missing_asset_is_reported_as_a_missing_asset(): void
    {
        $token = $this->asset(0xAA);

        try {
            CoinSelector::unlimited()->select(
                [$this->utxo(1, '500000000')],
                ValueBag::of('1000000', [[$token, Natural::of('5')]])
            );
            $this->fail('Selection should not have succeeded.');
        } catch (SelectionException $e) {
            $this->assertStringContainsString('5 of '.$token->key(), $e->getMessage());
            $this->assertStringNotContainsString('lovelace', $e->getMessage());
        }
    }

    public function test_a_lovelace_shortfall_is_reported_in_lovelace(): void
    {
        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('1000000 lovelace');

        CoinSelector::unlimited()->select(
            [$this->utxo(1, '4000000')],
            ValueBag::ofCoin('5000000')
        );
    }

    public function test_it_refuses_the_same_output_twice(): void
    {
        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('in the pool twice');

        CoinSelector::unlimited()->select(
            [$this->utxo(1, '5000000'), $this->utxo(1, '5000000')],
            ValueBag::ofCoin('1000000')
        );
    }

    public function test_it_refuses_to_exceed_the_input_limit(): void
    {
        $pool = [];
        for ($i = 1; $i <= 10; $i++) {
            $pool[] = $this->utxo($i, '1000000');
        }

        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('took 6 inputs and the limit is 3');

        CoinSelector::withLimits(SelectionLimits::maxInputs(3))->select($pool, ValueBag::ofCoin('6000000'));
    }

    /**
     * The same pool and the same target give the same inputs in the same order, every time, whatever order the pool
     * arrives in. Without that the fee fixed point settles on a different number per run and nothing downstream is
     * reproducible.
     */
    public function test_selection_is_deterministic_regardless_of_pool_order(): void
    {
        $token = $this->asset(0xAA);

        $pool = [
            $this->utxo(1, '3000000', [[$token, '2']]),
            $this->utxo(2, '3000000', [[$token, '2']]),
            $this->utxo(3, '3000000'),
            $this->utxo(4, '3000000'),
            $this->utxo(5, '3000000'),
        ];

        $target = ValueBag::of('8000000', [[$token, Natural::of('3')]]);

        $first = CoinSelector::unlimited()->select($pool, $target)->references();

        foreach ([array_reverse($pool), [$pool[2], $pool[0], $pool[4], $pool[1], $pool[3]]] as $shuffled) {
            $this->assertSame(
                $first,
                CoinSelector::unlimited()->select($shuffled, $target)->references()
            );
        }
    }

    /**
     * Selection arithmetic runs on quantities the chain can hold and PHP cannot. Sums of them are not bounded by
     * uint64 either, which is why the tally is a string throughout and never an int.
     */
    public function test_it_selects_quantities_past_the_php_integer_range(): void
    {
        $token = $this->asset(0xAA);

        $result = CoinSelector::unlimited()->select(
            [
                $this->utxo(1, '3000000', [[$token, Natural::UINT64_MAX]]),
                $this->utxo(2, '3000000', [[$token, Natural::UINT64_MAX]]),
            ],
            ValueBag::of('1000000', [[$token, Natural::of('18446744073709551616')]])
        );

        $this->assertSame(2, $result->inputCount());
        $this->assertSame(
            '36893488147419103230',
            $result->selected->quantityOf($token)->value
        );
        $this->assertSame('18446744073709551614', $result->surplus->quantityOf($token)->value);
    }

    /**
     * A UTxO built from every real mainnet output in the corpus, so the shape the selector works on is the shape the
     * chain writes and not one invented for the test.
     *
     * This is also where an assumption gets broken on purpose. "The value has an asset map" and "the output holds
     * assets" are not the same statement: 118 of the 9830 outputs here are written as a coin paired with an empty
     * policy map, which the ledger accepts and which a reader that treated the map's presence as a holding would
     * count as an asset-bearing output. The tally flattens it to nothing, which is what it is.
     */
    public function test_a_utxo_reads_every_real_mainnet_output_without_losing_anything(): void
    {
        $outputs = 0;
        $emptyPolicyMaps = 0;
        $holdingAssets = 0;

        foreach (LedgerFixtures::transactions() as $transaction) {
            foreach ($transaction['outputs'] as $index => $bytes) {
                $output = TransactionOutput::fromCbor(CborCodec::decode($bytes), 'output');
                $utxo = Utxo::fromOutput($transaction['tx'], $index, $output);

                $this->assertSame($output->value->coin->value, $utxo->coin()->value);
                $this->assertSame($output->value->assetCount(), $utxo->assetCount());
                $this->assertSame($transaction['tx'].'#'.$index, $utxo->reference());

                if ($output->value->hasAssets() && $output->value->assetCount() === 0) {
                    $emptyPolicyMaps++;
                    $this->assertTrue(
                        $utxo->isPureAda(),
                        'An empty policy map is a value holding no assets, whatever the encoding suggests.'
                    );
                }

                if ($utxo->assetCount() > 0) {
                    $holdingAssets++;
                }

                $outputs++;
            }
        }

        $this->assertGreaterThan(5000, $outputs);
        $this->assertGreaterThan(2000, $holdingAssets);
        $this->assertGreaterThan(
            0,
            $emptyPolicyMaps,
            'The corpus used to hold outputs written as a coin and an empty policy map, and the distinction between '
            .'"has an asset map" and "holds assets" is only tested by one.'
        );
    }
}
