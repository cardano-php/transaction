<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\MapForm;
use CBOR\ByteStringObject;
use CBOR\CBORObject;

/**
 * Policy to asset bundle. The shape the ledger calls multiasset, used both inside a value and as the mint field.
 *
 * The CDDL writes both levels with a one-or-more marker, and the inner level is held to it. The outer level is not:
 * thirty-one of the sixteen hundred transactions scanned to build the corpus pair a coin with an empty policy map,
 * the ledger took every one of them, and
 * a decoder that refused them would refuse mainnet history. Where the chain and the grammar disagree the chain wins,
 * because the chain is what has to be read back.
 */
final class MultiAsset
{
    /**
     * @param  list<AssetBundle>  $bundles
     */
    private function __construct(
        private readonly MapForm $form,
        private readonly array $bundles,
    ) {}

    /**
     * A multiasset map built rather than decoded.
     *
     * @param  list<AssetBundle>  $bundles
     */
    public static function of(array $bundles): self
    {
        return new self(MapForm::definite(), array_values($bundles));
    }

    public static function fromCbor(CBORObject $object, string $context, bool $signed): self
    {
        [$form, $entries] = MapForm::unwrap($object, $context);

        $bundles = [];
        foreach ($entries as [$policyId, $assets]) {
            $bundles[] = AssetBundle::fromCbor($policyId, $assets, $context, $signed);
        }

        return new self($form, $bundles);
    }

    public function toCbor(): CBORObject
    {
        $entries = [];
        foreach ($this->bundles as $bundle) {
            $entries[] = [ByteStringObject::create($bundle->policyId), $bundle->toCbor()];
        }

        return $this->form->wrap($entries);
    }

    /**
     * @return list<AssetBundle>
     */
    public function bundles(): array
    {
        return $this->bundles;
    }

    public function policyCount(): int
    {
        return count($this->bundles);
    }

    public function assetCount(): int
    {
        $total = 0;
        foreach ($this->bundles as $bundle) {
            $total += $bundle->count();
        }

        return $total;
    }
}
