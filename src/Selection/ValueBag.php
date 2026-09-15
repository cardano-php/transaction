<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\Value;

/**
 * A balance across every dimension at once: lovelace, and a quantity per asset class.
 *
 * This is the shape coin selection actually works in, and it is not the shape a Value has. A Value is what gets
 * written into an output, and it carries the CBOR forms it was read in so it can be written back unchanged. A bag
 * is arithmetic: totals get added, targets get subtracted, and nothing about how anything was encoded survives.
 *
 * Every quantity is a Natural, which means bcmath and a decimal string rather than a PHP integer. Two reasons, and
 * the second is the one people forget. A single ledger quantity is a uint64 and already passes what PHP's signed
 * integer holds. A *sum* of quantities across the outputs a wallet holds is not bounded by uint64 at all, so even
 * arithmetic that only ever touches quantities the chain could write has to happen in a type that has no ceiling.
 * ValuePacker is what puts the uint64 bound back, at the point a number is about to be written into an output.
 */
final class ValueBag
{
    /**
     * @param  array<string, array{AssetId, Natural}>  $assets
     */
    private function __construct(
        public readonly Natural $coin,
        private readonly array $assets,
    ) {}

    public static function empty(): self
    {
        return new self(Natural::zero(), []);
    }

    public static function ofCoin(Natural|int|string $coin): self
    {
        return new self($coin instanceof Natural ? $coin : Natural::of($coin), []);
    }

    /**
     * @param  list<array{AssetId, Natural|int|string}>  $assets
     */
    public static function of(Natural|int|string $coin, array $assets): self
    {
        $bag = self::ofCoin($coin);

        foreach ($assets as [$id, $quantity]) {
            $bag = $bag->plusAsset($id, $quantity instanceof Natural ? $quantity : Natural::of($quantity));
        }

        return $bag;
    }

    /**
     * Read a bag out of a decoded value.
     *
     * Quantities come across as the decimal strings they were decoded as, never through an int. An output holding
     * more than 2^63-1 of something is rare and entirely legal, and converting on the way in would turn it into a
     * crash or, worse, into a different number.
     */
    public static function fromValue(Value $value): self
    {
        $bag = self::ofCoin(Natural::of($value->coin->value));

        foreach ($value->assets?->bundles() ?? [] as $bundle) {
            foreach ($bundle->assets() as [$name, $quantity]) {
                $bag = $bag->plusAsset(
                    AssetId::of($bundle->policyId, $name),
                    Natural::of($quantity->value)
                );
            }
        }

        return $bag;
    }

    /**
     * The same assets with a different amount of lovelace.
     *
     * The packer measures with one coin and writes with another, and this is how it holds the assets fixed while it
     * does.
     */
    public function withCoin(Natural|int|string $coin): self
    {
        return new self($coin instanceof Natural ? $coin : Natural::of($coin), $this->assets);
    }

    public function plus(self $other): self
    {
        $result = new self($this->coin->plus($other->coin), $this->assets);

        foreach ($other->assets as [$id, $quantity]) {
            $result = $result->plusAsset($id, $quantity);
        }

        return $result;
    }

    public function plusAsset(AssetId $id, Natural $quantity): self
    {
        if ($quantity->isZero()) {
            return $this;
        }

        $assets = $this->assets;
        $key = $id->key();
        $assets[$key] = [$id, isset($assets[$key]) ? $assets[$key][1]->plus($quantity) : $quantity];

        return new self($this->coin, $assets);
    }

    /**
     * Subtract, refusing rather than going negative in any dimension.
     *
     * A bag is a balance. A negative balance is not a smaller balance, it is a transaction that does not add up, and
     * returning one here would carry that all the way to a node.
     */
    public function minus(self $other): self
    {
        if (! $this->covers($other)) {
            throw new SelectionException('Subtracting more than the bag holds: '.$this->describeShortfall($other));
        }

        $assets = $this->assets;
        foreach ($other->assets as $key => [$id, $quantity]) {
            $left = $assets[$key][1]->minus($quantity);
            if ($left->isZero()) {
                unset($assets[$key]);
            } else {
                $assets[$key] = [$id, $left];
            }
        }

        return new self($this->coin->minus($other->coin), $assets);
    }

    /** Whether this bag holds at least what the other one asks for, in every dimension. */
    public function covers(self $other): bool
    {
        if ($this->coin->isLessThan($other->coin)) {
            return false;
        }

        foreach ($other->assets as $key => [, $quantity]) {
            if (! isset($this->assets[$key]) || $this->assets[$key][1]->isLessThan($quantity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What is missing, named dimension by dimension.
     *
     * Named, because "insufficient funds" said to an operator whose wallet is full of ADA and empty of the token
     * being paid out sends them to look at the wrong number.
     */
    public function shortfall(self $target): self
    {
        [, $coinShort] = $this->coin->minusReportingShortfall($target->coin);
        $missing = self::ofCoin($coinShort);

        foreach ($target->assets as $key => [$id, $quantity]) {
            $held = $this->assets[$key][1] ?? Natural::zero();
            [, $short] = $held->minusReportingShortfall($quantity);
            $missing = $missing->plusAsset($id, $short);
        }

        return $missing;
    }

    public function describeShortfall(self $target): string
    {
        $missing = $this->shortfall($target);
        $parts = [];

        if (! $missing->coin->isZero()) {
            $parts[] = $missing->coin->value.' lovelace';
        }

        foreach ($missing->assets as [$id, $quantity]) {
            $parts[] = $quantity->value.' of '.$id->key();
        }

        return $parts === [] ? 'nothing' : implode(', ', $parts);
    }

    public function quantityOf(AssetId $id): Natural
    {
        return $this->assets[$id->key()][1] ?? Natural::zero();
    }

    public function holds(AssetId $id): bool
    {
        return isset($this->assets[$id->key()]);
    }

    /**
     * @return list<array{AssetId, Natural}>
     */
    public function assets(): array
    {
        return array_values($this->assets);
    }

    public function assetCount(): int
    {
        return count($this->assets);
    }

    public function isEmpty(): bool
    {
        return $this->coin->isZero() && $this->assets === [];
    }

    public function hasAssets(): bool
    {
        return $this->assets !== [];
    }

    /**
     * Write the bag back out as a value, grouped by policy the way the ledger writes it.
     *
     * Order is decided here rather than left to whatever order things were added in. Two runs of the same selection
     * have to produce the same bytes, or the fee fixed point settles on a different number each time and no test of
     * it means anything. Policies are ordered by their bytes and asset names by theirs. That is determinism, which
     * is what is needed; it is not canonicality, which the ledger does not ask for and which step 1 deliberately
     * does not claim.
     *
     * The uint64 bound applies here and nowhere earlier. Up to this point a quantity is a running total, and a sum
     * across a wallet's outputs is not bounded by what one output can hold. Here it stops being a total and becomes
     * a field, and Natural::quantity is what refuses one that will not fit.
     */
    public function toValue(): Value
    {
        if ($this->assets === []) {
            return Value::lovelace($this->coin->value);
        }

        $byPolicy = [];
        foreach ($this->assets as [$id, $quantity]) {
            $byPolicy[bin2hex($id->policyId)][bin2hex($id->name)] = [
                $id,
                Natural::quantity($quantity->value),
            ];
        }

        ksort($byPolicy, SORT_STRING);

        $bundles = [];
        foreach ($byPolicy as $names) {
            ksort($names, SORT_STRING);

            $policyId = null;
            $assets = [];
            foreach ($names as [$id, $quantity]) {
                $policyId = $id->policyId;
                $assets[] = [$id->name, $quantity->value];
            }

            $bundles[] = AssetBundle::of((string) $policyId, $assets);
        }

        return Value::of($this->coin->value, MultiAsset::of($bundles));
    }
}
