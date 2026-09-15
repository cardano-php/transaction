<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Exception\DecodeException;
use CBOR\CBORObject;
use CBOR\UnsignedIntegerObject;

/**
 * What an output holds: lovelace on its own, or lovelace and a multiasset map as a pair.
 */
final class Value
{
    private function __construct(
        public readonly CborInteger $coin,
        public readonly ?MultiAsset $assets,
        private readonly ?SequenceForm $form,
    ) {}

    /**
     * A value built rather than decoded.
     *
     * With no assets it is a bare coin, which is the only form the ledger accepts for one; with assets it is the
     * pair. A coin paired with an empty asset map is legal on chain and is never produced here, because it costs
     * bytes and says nothing.
     */
    public static function of(int|string $coin, ?MultiAsset $assets = null): self
    {
        if ($assets === null || $assets->policyCount() === 0) {
            return new self(CborInteger::of($coin), null, null);
        }

        return new self(CborInteger::of($coin), $assets, SequenceForm::definite());
    }

    public static function lovelace(int|string $coin): self
    {
        return self::of($coin, null);
    }

    /**
     * The same value with a different coin, keeping the asset map and the form it was written in.
     *
     * This is what the minimum-UTxO fixed point walks: raising the coin can widen the integer that holds it, which
     * grows the output, which raises the minimum again.
     */
    public function withCoin(int|string $coin): self
    {
        return new self(CborInteger::of($coin), $this->assets, $this->form);
    }

    /** The serialized bytes of the value on its own, which is what maxValueSize is measured against. */
    public function encode(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    public static function fromCbor(CBORObject $object, string $context): self
    {
        if ($object instanceof UnsignedIntegerObject) {
            return new self(CborInteger::unsignedFromCbor($object, $context.' coin'), null, null);
        }

        [$form, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if (count($items) !== 2) {
            throw new DecodeException(sprintf(
                '%s: a value with assets is a pair, got %d item(s).',
                $context,
                count($items)
            ));
        }

        return new self(
            CborInteger::unsignedFromCbor($items[0], $context.' coin'),
            MultiAsset::fromCbor($items[1], $context.' assets', signed: false),
            $form,
        );
    }

    public function toCbor(): CBORObject
    {
        if ($this->assets === null || $this->form === null) {
            return $this->coin->toCbor();
        }

        return $this->form->wrap([$this->coin->toCbor(), $this->assets->toCbor()]);
    }

    public function hasAssets(): bool
    {
        return $this->assets !== null;
    }

    public function assetCount(): int
    {
        return $this->assets?->assetCount() ?? 0;
    }
}
