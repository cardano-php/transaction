<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\ValueSize;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Selection\AssetId;
use Cardano\Transaction\Selection\ChangeStrategy;
use Cardano\Transaction\Selection\CoinSelector;
use Cardano\Transaction\Selection\SingleChangeOutput;
use Cardano\Transaction\Selection\Utxo;
use Cardano\Transaction\Selection\ValueBag;
use PHPUnit\Framework\TestCase;

/**
 * The contract every change strategy has to meet, written against the interface rather than against the placeholder.
 *
 * The change algorithm is specified separately, from the UnFrackIt work, and arrives with its own vector set.
 * Nothing here anticipates what it decides. What is here are the three things it cannot be allowed to get wrong
 * whatever it decides, and they are asserted through ChangeStrategy so that the implementation that lands is held
 * to them from the moment it is installed:
 *
 * 1. Every token in the surplus comes back. Losing one is burning somebody's property.
 * 2. Every output returned satisfies its own minimum UTxO.
 * 3. Every output returned is inside maxValueSize.
 *
 * The placeholder is run against all three, and so is a second, deliberately strange strategy, to show the tests
 * are about the interface and not about the one implementation that exists.
 */
class ChangeSeamTest extends TestCase
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
    private function assets(int $count): array
    {
        $assets = [];
        for ($i = 0; $i < $count; $i++) {
            $assets[] = [
                AssetId::of(str_pad(pack('N', $i), 28, "\xEE"), 'T'.$i),
                Natural::of((string) (1 + $i)),
            ];
        }

        return $assets;
    }

    /**
     * @return list<ChangeStrategy>
     */
    private function strategies(): array
    {
        return [
            new SingleChangeOutput,

            // A second strategy that splits the lovelace across two outputs before packing, so the assertions below
            // are demonstrably about the interface rather than about SingleChangeOutput's particular answer.
            new class implements ChangeStrategy
            {
                public function change(ValueBag $surplus, string $changeAddress, LedgerParameters $parameters): array
                {
                    if ($surplus->isEmpty()) {
                        return [];
                    }

                    $minimum = MinimumUtxo::under($parameters);
                    $spare = $minimum->forValue($changeAddress, \Cardano\Transaction\Primitives\Value::lovelace('0'));

                    if ($surplus->coin->isLessThan($spare->times(2))) {
                        return (new SingleChangeOutput)->change($surplus, $changeAddress, $parameters);
                    }

                    $split = $minimum->fund(
                        $changeAddress,
                        \Cardano\Transaction\Primitives\Value::lovelace($spare->value)
                    );

                    $rest = $surplus->withCoin($surplus->coin->minus(
                        Natural::of($split->value->coin->value)
                    ));

                    return [
                        $split,
                        ...(new SingleChangeOutput)->change($rest, $changeAddress, $parameters),
                    ];
                }
            },
        ];
    }

    public function test_every_strategy_returns_every_token_in_the_surplus(): void
    {
        foreach ($this->strategies() as $strategy) {
            foreach ([0, 1, 30, 200] as $count) {
                $surplus = ValueBag::of('400000000', $this->assets($count));
                $outputs = $strategy->change($surplus, $this->address(), $this->parameters());

                $returned = ValueBag::empty();
                foreach ($outputs as $output) {
                    $returned = $returned->plus(ValueBag::fromValue($output->value));
                }

                $this->assertSame(
                    $surplus->coin->value,
                    $returned->coin->value,
                    sprintf('%s lost lovelace on a surplus of %d assets.', $strategy::class, $count)
                );

                foreach ($surplus->assets() as [$asset, $quantity]) {
                    $this->assertSame(
                        $quantity->value,
                        $returned->quantityOf($asset)->value,
                        sprintf('%s lost %s.', $strategy::class, $asset->key())
                    );
                }
            }
        }
    }

    public function test_every_strategy_returns_outputs_that_satisfy_both_ledger_rules(): void
    {
        $minimum = MinimumUtxo::under($this->parameters());
        $sizes = ValueSize::under($this->parameters());

        foreach ($this->strategies() as $strategy) {
            foreach ([1, 30, 200] as $count) {
                $surplus = ValueBag::of('400000000', $this->assets($count));

                foreach ($strategy->change($surplus, $this->address(), $this->parameters()) as $index => $output) {
                    $this->assertTrue(
                        $minimum->isSatisfiedBy($output),
                        sprintf('%s output %d is below its minimum UTxO.', $strategy::class, $index)
                    );
                    $this->assertTrue(
                        $sizes->outputFits($output),
                        sprintf('%s output %d is over maxValueSize.', $strategy::class, $index)
                    );
                }
            }
        }
    }

    public function test_an_empty_surplus_produces_no_change(): void
    {
        foreach ($this->strategies() as $strategy) {
            $this->assertSame([], $strategy->change(ValueBag::empty(), $this->address(), $this->parameters()));
        }
    }

    /**
     * A surplus too small to be a change output is a decision, not an arithmetic result. Giving it to the fee is
     * moving the operator's money, and the placeholder refuses rather than deciding on the operator's behalf.
     */
    public function test_a_surplus_below_the_minimum_is_refused_rather_than_absorbed(): void
    {
        $minimum = SingleChangeOutput::minimumLovelaceChange($this->address(), $this->parameters());

        $this->expectException(SelectionException::class);
        $this->expectExceptionMessage('is a decision about the operator');

        (new SingleChangeOutput)->change(
            ValueBag::ofCoin($minimum->minus(Natural::of(1))->value),
            $this->address(),
            $this->parameters()
        );
    }

    public function test_a_surplus_exactly_at_the_minimum_is_accepted(): void
    {
        $minimum = SingleChangeOutput::minimumLovelaceChange($this->address(), $this->parameters());

        $outputs = (new SingleChangeOutput)->change(
            ValueBag::ofCoin($minimum->value),
            $this->address(),
            $this->parameters()
        );

        $this->assertCount(1, $outputs);
        $this->assertSame($minimum->value, $outputs[0]->value->coin->value);
    }

    /**
     * Selection and change compose: what the selector leaves over is what the strategy is handed, and between them
     * nothing is created or destroyed.
     */
    public function test_selection_and_change_conserve_the_inputs(): void
    {
        $token = AssetId::of(str_repeat("\xAA", 28), 'TOKEN');

        $pool = [
            Utxo::of(str_repeat('1', 64), 0, $this->address(), ValueBag::of('20000000', [[$token, Natural::of('400')]])),
            Utxo::of(str_repeat('2', 64), 1, $this->address(), ValueBag::ofCoin('15000000')),
        ];

        $target = ValueBag::of('25000000', [[$token, Natural::of('1')]]);

        $result = CoinSelector::unlimited()->select($pool, $target);
        $change = (new SingleChangeOutput)->change($result->surplus, $this->address(), $this->parameters());

        $out = ValueBag::empty()->plus($target);
        foreach ($change as $output) {
            $out = $out->plus(ValueBag::fromValue($output->value));
        }

        $in = ValueBag::empty();
        foreach ($result->inputs as $utxo) {
            $in = $in->plus($utxo->value);
        }

        $this->assertSame($in->coin->value, $out->coin->value);
        $this->assertSame('400', $out->quantityOf($token)->value);
    }

    /**
     * A strategy returning outputs at a different address, or holding less than it was given, is exactly what the
     * conservation test above is meant to catch. Stated here so that the test is shown to fail when it should.
     */
    public function test_the_conservation_check_catches_a_strategy_that_drops_a_token(): void
    {
        $dropper = new class implements ChangeStrategy
        {
            public function change(ValueBag $surplus, string $changeAddress, LedgerParameters $parameters): array
            {
                return [TransactionOutput::create(
                    $changeAddress,
                    \Cardano\Transaction\Primitives\Value::lovelace($surplus->coin->value)
                )];
            }
        };

        $token = AssetId::of(str_repeat("\xAA", 28), 'TOKEN');
        $surplus = ValueBag::of('20000000', [[$token, Natural::of('400')]]);

        $returned = ValueBag::empty();
        foreach ($dropper->change($surplus, $this->address(), $this->parameters()) as $output) {
            $returned = $returned->plus(ValueBag::fromValue($output->value));
        }

        $this->assertSame('0', $returned->quantityOf($token)->value);
        $this->assertFalse(
            $returned->covers($surplus),
            'A strategy that keeps the lovelace and drops the token has to fail the conservation check.'
        );
    }
}
