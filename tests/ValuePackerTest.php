<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\ValueSize;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Selection\AssetId;
use Cardano\Transaction\Selection\ValueBag;
use Cardano\Transaction\Selection\ValuePacker;
use PHPUnit\Framework\TestCase;

/**
 * Packing a bag of assets into outputs, with the size limit applied to bytes rather than to a count.
 */
class ValuePackerTest extends TestCase
{
    private function parameters(): LedgerParameters
    {
        return LedgerFixtures::parameters();
    }

    private function address(): string
    {
        return "\x01".str_repeat("\x11", 28).str_repeat("\x22", 28);
    }

    /**
     * @return list<array{AssetId, Natural}>
     */
    private function assets(int $count, int $nameLength = 32, string $quantity = '1'): array
    {
        $assets = [];
        for ($i = 0; $i < $count; $i++) {
            $policy = str_pad(pack('N', $i), 28, "\xEE");
            $assets[] = [
                AssetId::of($policy, str_repeat('n', $nameLength)),
                Natural::of($quantity),
            ];
        }

        return $assets;
    }

    public function test_a_lovelace_only_bag_packs_into_one_output(): void
    {
        $outputs = ValuePacker::under($this->parameters())
            ->pack(ValueBag::ofCoin('10000000'), $this->address());

        $this->assertCount(1, $outputs);
        $this->assertSame('10000000', $outputs[0]->value->coin->value);
    }

    public function test_an_empty_bag_packs_into_nothing(): void
    {
        $this->assertSame([], ValuePacker::under($this->parameters())->pack(ValueBag::empty(), $this->address()));
    }

    /**
     * Every output the packer produces satisfies both rules on the bytes it actually wrote, not on the bytes it
     * measured while deciding.
     */
    public function test_every_packed_output_satisfies_both_rules_as_written(): void
    {
        $packer = ValuePacker::under($this->parameters());
        $sizes = ValueSize::under($this->parameters());
        $minimum = MinimumUtxo::under($this->parameters());

        foreach ([1, 5, 40, 120, 400] as $count) {
            $bag = ValueBag::of('500000000', $this->assets($count));
            $outputs = $packer->pack($bag, $this->address());

            foreach ($outputs as $index => $output) {
                $this->assertTrue(
                    $sizes->outputFits($output),
                    sprintf('%d assets, output %d is over maxValueSize.', $count, $index)
                );
                $this->assertTrue(
                    $minimum->isSatisfiedBy($output),
                    sprintf('%d assets, output %d is below its minimum UTxO.', $count, $index)
                );
            }
        }
    }

    /**
     * Nothing is lost and nothing is invented. This is the property a change implementation will be held to, and it
     * is checked here because the packer is what a change implementation will be built on.
     */
    public function test_packing_preserves_the_bag_exactly(): void
    {
        $packer = ValuePacker::under($this->parameters());

        foreach ([1, 40, 400] as $count) {
            $bag = ValueBag::of('500000000', $this->assets($count));
            $outputs = $packer->pack($bag, $this->address());

            $total = ValueBag::empty();
            foreach ($outputs as $output) {
                $total = $total->plus(ValueBag::fromValue($output->value));
            }

            $this->assertSame($bag->coin->value, $total->coin->value, 'Lovelace went missing.');
            $this->assertSame($bag->assetCount(), $total->assetCount(), 'An asset class went missing.');

            foreach ($bag->assets() as [$asset, $quantity]) {
                $this->assertSame(
                    $quantity->value,
                    $total->quantityOf($asset)->value,
                    'The quantity of '.$asset->key().' changed in packing.'
                );
            }
        }
    }

    /**
     * The whole point of measuring. The same number of assets packs into one output or several depending on how
     * long their names are and how large their quantities are, and no count-based rule can produce both answers.
     */
    public function test_the_same_asset_count_packs_differently_depending_on_its_bytes(): void
    {
        $packer = ValuePacker::under($this->parameters());

        // Seventy under seventy policies. Named and quantified one way they take 2310 bytes; named and quantified
        // the other they take 5180, and maxValueSize is 5000.
        $short = $packer->pack(
            ValueBag::of('500000000', $this->assets(70, nameLength: 0, quantity: '1')),
            $this->address()
        );

        $long = $packer->pack(
            ValueBag::of('500000000', $this->assets(70, nameLength: 32, quantity: Natural::UINT64_MAX)),
            $this->address()
        );

        $this->assertCount(1, $short, 'Seventy small assets fit one output.');
        $this->assertGreaterThan(
            1,
            count($long),
            'Seventy assets with full names and full quantities cannot fit the same output, and only the bytes say '
            .'so: the count is identical.'
        );
    }

    public function test_it_splits_a_bag_too_large_for_one_output(): void
    {
        $packer = ValuePacker::under($this->parameters());
        $sizes = ValueSize::under($this->parameters());

        $outputs = $packer->pack(ValueBag::of('900000000', $this->assets(400)), $this->address());

        $this->assertGreaterThan(1, count($outputs));

        // Packing has to be dense, not merely correct: an implementation that put one asset per output would pass
        // every other assertion here and produce a transaction nobody can afford.
        foreach (array_slice($outputs, 0, -1) as $index => $output) {
            $this->assertGreaterThan(
                $sizes->limit()->toInt() / 2,
                ValueSize::ofOutput($output),
                sprintf('Output %d is less than half full, so the packer is not filling outputs before it splits.',
                    $index)
            );
        }
    }

    /**
     * Each output the split produces needs a minimum of its own, out of the same lovelace. A bag that could fund one
     * output cannot necessarily fund the two its assets force, and being told that is better than building a
     * transaction the node refuses.
     */
    public function test_it_refuses_a_bag_whose_lovelace_cannot_fund_the_outputs_its_assets_force(): void
    {
        $packer = ValuePacker::under($this->parameters());

        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('minimum UTxO between them');

        $packer->pack(ValueBag::of('2000000', $this->assets(400)), $this->address());
    }

    /**
     * Quantities past what a PHP integer holds pack like any other. The bound that does apply is the ledger's, and
     * it applies where a total becomes a field.
     */
    public function test_it_packs_quantities_past_the_php_integer_range(): void
    {
        $packer = ValuePacker::under($this->parameters());

        $bag = ValueBag::of('20000000', [
            [AssetId::of(str_repeat("\xAA", 28), 'BIG'), Natural::of(Natural::UINT64_MAX)],
        ]);

        $outputs = $packer->pack($bag, $this->address());

        $this->assertCount(1, $outputs);
        $this->assertSame(
            Natural::UINT64_MAX,
            ValueBag::fromValue($outputs[0]->value)
                ->quantityOf(AssetId::of(str_repeat("\xAA", 28), 'BIG'))
                ->value
        );
    }

    /**
     * A quantity above what the ledger can write is refused at the point it would be written, not earlier. A running
     * total larger than uint64 is a perfectly ordinary thing for a wallet to hold across several outputs.
     */
    public function test_a_total_above_uint64_is_refused_only_when_it_becomes_an_output(): void
    {
        $asset = AssetId::of(str_repeat("\xAA", 28), 'BIG');

        $bag = ValueBag::ofCoin('20000000')
            ->plusAsset($asset, Natural::of(Natural::UINT64_MAX))
            ->plusAsset($asset, Natural::of('1'));

        $this->assertSame('18446744073709551616', $bag->quantityOf($asset)->value);

        $this->expectException(\Cardano\Transaction\Exception\ArithmeticException::class);
        $this->expectExceptionMessage('at most 18446744073709551615');

        ValuePacker::under($this->parameters())->pack($bag, $this->address());
    }

    /**
     * Packed outputs re-read as what was packed. The encoder and the decoder are the same pair the corpus tests
     * run over, so an output built here is one the chain would read the same way.
     */
    public function test_a_packed_output_decodes_back_to_itself(): void
    {
        $outputs = ValuePacker::under($this->parameters())
            ->pack(ValueBag::of('50000000', $this->assets(30)), $this->address());

        foreach ($outputs as $output) {
            $bytes = $output->encode();
            $again = TransactionOutput::fromCbor(CborCodec::decode($bytes), 'output');

            $this->assertSame(bin2hex($bytes), bin2hex($again->encode()));
            $this->assertSame($output->address, $again->address);
        }
    }
}
