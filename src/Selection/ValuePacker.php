<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\ValueSize;
use Cardano\Transaction\Primitives\TransactionOutput;

/**
 * Spreading a bag of assets across as few outputs as will hold it, measuring as it goes.
 *
 * The rule is maxValueSize, stated in bytes of the serialized value, and the only honest way to apply it is to
 * serialize. This class adds one asset, encodes the value, and looks at the length. If the value is over, that asset
 * starts the next output instead. It never counts assets and never estimates from a count, because the count is not
 * the size: a policy id is twenty-eight bytes and is paid for once per policy however many assets sit under it, an
 * asset name is anywhere from nought to thirty-two bytes, and a quantity is one byte or nine depending on how large
 * it is. Two bags of a hundred assets each can differ by a factor of five in serialized size.
 *
 * What comes back is outputs that each satisfy the minimum UTxO for their own size. That is not free and it is not
 * an accident: an output carrying assets needs more lovelace than an empty one, and the second output a packer
 * creates needs a minimum of its own, out of the same lovelace. If there is not enough, the packer says so with the
 * figure, rather than returning outputs a node will refuse.
 *
 * One asset that is too large to fit any output on its own is a separate failure and is reported separately. It
 * cannot be packed at all, at any coin, and telling the caller to add lovelace would be wrong.
 */
final class ValuePacker
{
    private function __construct(
        private readonly LedgerParameters $parameters,
        private readonly ValueSize $sizes,
        private readonly MinimumUtxo $minimum,
    ) {}

    public static function under(LedgerParameters $parameters): self
    {
        return new self($parameters, ValueSize::under($parameters), MinimumUtxo::under($parameters));
    }

    /**
     * Pack everything in the bag into outputs at one address.
     *
     * Lovelace is spent on the minimums first, in order, and whatever is left goes to the last output. An empty bag
     * produces no outputs, which is a caller asking for nothing rather than an error.
     *
     * @return list<TransactionOutput>
     */
    public function pack(ValueBag $bag, string $address): array
    {
        if ($bag->isEmpty()) {
            return [];
        }

        $groups = $this->group($bag, $address);

        $outputs = [];
        $spent = Natural::zero();

        foreach ($groups as $group) {
            $required = $this->minimum->forValue($address, $group->withCoin('0')->toValue());
            $spent = $spent->plus($required);
            $outputs[] = TransactionOutput::create($address, $group->withCoin($required->value)->toValue());
        }

        if ($bag->coin->isLessThan($spent)) {
            throw new SelectionException(sprintf(
                'Holding these assets takes %d output(s) and %s lovelace of minimum UTxO between them, and the bag '
                .'carries %s.',
                count($outputs),
                $spent->value,
                $bag->coin->value
            ));
        }

        $remainder = $bag->coin->minus($spent);

        if (! $remainder->isZero()) {
            $last = count($outputs) - 1;
            $topUp = Natural::of($outputs[$last]->value->coin->value)->plus($remainder);
            $outputs[$last] = $outputs[$last]->withValue($outputs[$last]->value->withCoin($topUp->value));
        }

        $this->verify($outputs);

        return $outputs;
    }

    /**
     * Split the assets into groups, each of which serializes inside maxValueSize.
     *
     * The coin used while measuring is the bag's own, not nought. A group measured with a one-byte coin and then
     * written with a nine-byte one is a group measured eight bytes too small, and on a bag sitting right at the
     * limit that is the difference between a transaction and a rejection.
     *
     * @return list<ValueBag>
     */
    private function group(ValueBag $bag, string $address): array
    {
        $assets = $bag->assets();
        usort($assets, static fn (array $a, array $b): int => strcmp($a[0]->key(), $b[0]->key()));

        $groups = [];
        $current = ValueBag::ofCoin($bag->coin);
        $currentHasAssets = false;

        foreach ($assets as [$asset, $quantity]) {
            $candidate = $current->plusAsset($asset, $quantity);

            if ($this->sizes->fits($candidate->toValue())) {
                $current = $candidate;
                $currentHasAssets = true;

                continue;
            }

            if (! $currentHasAssets) {
                throw new SelectionException(sprintf(
                    'The asset %s serializes to %d bytes on its own, and maxValueSize is %s. It cannot be held by '
                    .'any output.',
                    $asset->key(),
                    ValueSize::of($candidate->toValue()),
                    $this->parameters->maxValueSize->value
                ));
            }

            $groups[] = $current;
            $current = ValueBag::ofCoin($bag->coin)->plusAsset($asset, $quantity);
            $currentHasAssets = true;

            if (! $this->sizes->fits($current->toValue())) {
                throw new SelectionException(sprintf(
                    'The asset %s serializes to %d bytes on its own, and maxValueSize is %s. It cannot be held by '
                    .'any output.',
                    $asset->key(),
                    ValueSize::of($current->toValue()),
                    $this->parameters->maxValueSize->value
                ));
            }
        }

        $groups[] = $current;

        return $groups;
    }

    /**
     * Read the answer back against both rules, on the outputs as they will actually be written.
     *
     * The packing loop measured values with a provisional coin. The outputs that come out of it carry their final
     * coins, one of which absorbed the whole remainder and may be several bytes wider than what was measured. This
     * is where that is caught, on the finished bytes, rather than trusted.
     *
     * @param  list<TransactionOutput>  $outputs
     */
    private function verify(array $outputs): void
    {
        foreach ($outputs as $index => $output) {
            if (! $this->sizes->outputFits($output)) {
                throw new SelectionException(sprintf(
                    'Packed output %d holds a value of %d bytes and maxValueSize is %s.',
                    $index,
                    ValueSize::ofOutput($output),
                    $this->parameters->maxValueSize->value
                ));
            }

            if (! $this->minimum->isSatisfiedBy($output)) {
                throw new SelectionException(sprintf(
                    'Packed output %d holds %s lovelace and its minimum UTxO is %s.',
                    $index,
                    $output->value->coin->value,
                    $this->minimum->forOutput($output)->value
                ));
            }
        }
    }
}
