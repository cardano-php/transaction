<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\Natural;

/**
 * Choosing which unspent outputs to spend.
 *
 * Selection on Cardano is not a knapsack over one number. An output holds lovelace and, independently, a quantity of
 * each of any number of asset classes, and a target may ask for some of each. A selector that only chases lovelace
 * will happily gather ten million lovelace out of ADA-only outputs and hand back a transaction that pays out a token
 * it never picked up, and the node refuses it with a message about value not being preserved. So selection runs in
 * two directions.
 *
 * **Asset-directed first.** For each asset the target asks for, take outputs holding that asset until the quantity
 * is met. Largest holding first, which keeps the input count down, and the reference string breaks ties so two runs
 * of the same selection produce the same transaction. This runs first because an output chosen for its token brings
 * its lovelace along, and counting that lovelace before deciding how much more is needed is what stops the selector
 * dragging in ADA it already has.
 *
 * **Value-directed second.** Whatever lovelace is still short comes from the rest, and ADA-only outputs are
 * preferred over ones carrying assets. Not for tidiness: every asset dragged in has to be sent back out as change,
 * where it costs bytes in the change output, raises that output's minimum UTxO, and can push the change value past
 * maxValueSize into a second output that needs a minimum of its own. An ADA-only output costs none of that.
 *
 * What this class does **not** do is decide what change looks like. That is the seam. See ChangeStrategy.
 */
final class CoinSelector
{
    private function __construct(private readonly SelectionLimits $limits) {}

    public static function withLimits(SelectionLimits $limits): self
    {
        return new self($limits);
    }

    public static function unlimited(): self
    {
        return new self(SelectionLimits::none());
    }

    /**
     * @param  list<Utxo>  $available
     */
    public function select(array $available, ValueBag $target): SelectionResult
    {
        $pool = $this->order($available);
        $chosen = [];
        $selected = ValueBag::empty();

        foreach ($target->assets() as [$asset, $required]) {
            [$pool, $chosen, $selected] = $this->takeForAsset($pool, $chosen, $selected, $asset, $required);
        }

        [$chosen, $selected] = $this->takeForCoin($pool, $chosen, $selected, $target->coin);

        if (! $selected->covers($target)) {
            throw new SelectionException(sprintf(
                'The available outputs are short of the target by %s.',
                $selected->describeShortfall($target)
            ));
        }

        $this->limits->check(count($chosen));

        return new SelectionResult(
            array_values($chosen),
            $selected,
            $target,
            $selected->minus($target),
        );
    }

    /**
     * The asset-directed pass, for one asset class.
     *
     * @param  list<Utxo>  $pool
     * @param  array<string, Utxo>  $chosen
     * @return array{list<Utxo>, array<string, Utxo>, ValueBag}
     */
    private function takeForAsset(
        array $pool,
        array $chosen,
        ValueBag $selected,
        AssetId $asset,
        Natural $required
    ): array {
        if ($selected->quantityOf($asset)->isAtLeast($required)) {
            return [$pool, $chosen, $selected];
        }

        $holders = array_values(array_filter($pool, static fn (Utxo $u): bool => $u->holds($asset)));

        usort($holders, static function (Utxo $a, Utxo $b) use ($asset): int {
            $byQuantity = $b->quantityOf($asset)->compare($a->quantityOf($asset));

            return $byQuantity !== 0 ? $byQuantity : strcmp($a->reference(), $b->reference());
        });

        foreach ($holders as $utxo) {
            if ($selected->quantityOf($asset)->isAtLeast($required)) {
                break;
            }

            $chosen[$utxo->reference()] = $utxo;
            $selected = $selected->plus($utxo->value);
        }

        $pool = array_values(array_filter(
            $pool,
            static fn (Utxo $u): bool => ! isset($chosen[$u->reference()])
        ));

        return [$pool, $chosen, $selected];
    }

    /**
     * The value-directed pass.
     *
     * @param  list<Utxo>  $pool
     * @param  array<string, Utxo>  $chosen
     * @return array{array<string, Utxo>, ValueBag}
     */
    private function takeForCoin(array $pool, array $chosen, ValueBag $selected, Natural $required): array
    {
        usort($pool, static function (Utxo $a, Utxo $b): int {
            if ($a->isPureAda() !== $b->isPureAda()) {
                return $a->isPureAda() ? -1 : 1;
            }

            $byCoin = $b->coin()->compare($a->coin());

            return $byCoin !== 0 ? $byCoin : strcmp($a->reference(), $b->reference());
        });

        foreach ($pool as $utxo) {
            if ($selected->coin->isAtLeast($required)) {
                break;
            }

            $chosen[$utxo->reference()] = $utxo;
            $selected = $selected->plus($utxo->value);
        }

        return [$chosen, $selected];
    }

    /**
     * Put the pool in a fixed order and refuse a duplicate.
     *
     * The same output twice is not a bigger balance, it is a transaction that spends one input twice, and the node
     * refuses it. Catching it here names the output; catching it at submission does not.
     *
     * @param  list<Utxo>  $available
     * @return list<Utxo>
     */
    private function order(array $available): array
    {
        $seen = [];
        foreach ($available as $utxo) {
            $reference = $utxo->reference();
            if (isset($seen[$reference])) {
                throw new SelectionException(sprintf('The output %s is in the pool twice.', $reference));
            }
            $seen[$reference] = $utxo;
        }

        $pool = array_values($seen);
        usort($pool, static fn (Utxo $a, Utxo $b): int => strcmp($a->reference(), $b->reference()));

        return $pool;
    }
}
