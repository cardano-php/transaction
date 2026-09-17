<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Exception\DecodeException;

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

    /**
     * The same bundles, written in the order canonical CBOR puts them in.
     *
     * The ledger does not ask for canonical ordering and accepts a multiasset map written any way round, so nothing
     * here is about validity. Two other things are.
     *
     * A map key is ordered by its encoded bytes, which for a byte string means shorter keys first and then
     * lexicographically. That is not the same as sorting the raw names: a one byte name beginning 0x7a sorts before
     * a six byte name beginning 0x4f under the encoded rule and after it under the raw one, so the two orderings
     * disagree the moment a policy holds names of different lengths. Sorting here is the encoded rule, taken from
     * the encoding rather than argued about, so it cannot drift from what the encoder does.
     *
     * The reason to have it at all is what else reads these bytes. Every other builder emits the canonical order,
     * which is what makes a byte comparison against one meaningful, and a hardware wallet asked to sign a
     * transaction whose multiasset maps are out of canonical order refuses it. A transaction this application signs
     * with its own key does not care; one handed to a customer's wallet to sign does.
     *
     * Duplicates are refused rather than merged. Two entries for one policy make a CBOR map with a repeated key,
     * which is not a map, and quietly adding the quantities together would turn a caller's mistake into a different
     * transaction from the one they asked for.
     *
     * @param  list<AssetBundle>  $bundles
     */
    public static function canonical(array $bundles): self
    {
        $ordered = array_values($bundles);

        $seen = [];
        foreach ($ordered as $bundle) {
            if (isset($seen[$bundle->policyId])) {
                throw new DecodeException(sprintf(
                    'The policy %s appears twice; a multiasset map holds each policy once.',
                    $bundle->policyIdHex()
                ));
            }

            $seen[$bundle->policyId] = true;
        }

        usort(
            $ordered,
            static fn (AssetBundle $a, AssetBundle $b): int => self::compareKeys($a->policyId, $b->policyId)
        );

        return new self(
            MapForm::definite(),
            array_map(static fn (AssetBundle $bundle): AssetBundle => $bundle->canonical(), $ordered)
        );
    }

    /**
     * Order two map keys the way canonical CBOR does: by the bytes they encode to, not by the bytes they hold.
     */
    public static function compareKeys(string $a, string $b): int
    {
        return strcmp(
            CborCodec::encode(CborValue::byteString($a)),
            CborCodec::encode(CborValue::byteString($b))
        );
    }

    public static function fromCbor(CborValue $object, string $context, bool $signed): self
    {
        [$form, $entries] = MapForm::unwrap($object, $context);

        $bundles = [];
        foreach ($entries as [$policyId, $assets]) {
            $bundles[] = AssetBundle::fromCbor($policyId, $assets, $context, $signed);
        }

        return new self($form, $bundles);
    }

    public function toCbor(): CborValue
    {
        $entries = [];
        foreach ($this->bundles as $bundle) {
            $entries[] = [CborValue::byteString($bundle->policyId), $bundle->toCbor()];
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
