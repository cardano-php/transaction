<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\ByteStringForm;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;

/**
 * The assets of one policy: asset name to quantity.
 *
 * Quantities are signed inside a mint field and unsigned everywhere else, which is what separates burning a token
 * from holding one, so the caller says which it is reading.
 */
final class AssetBundle
{
    /**
     * @param  list<array{string, CborInteger}>  $assets
     * @param  list<ByteStringForm>  $assetNameForms  the head each asset name arrived in, one per entry of $assets
     */
    private function __construct(
        public readonly string $policyId,
        private readonly MapForm $form,
        private readonly array $assets,
        private readonly ByteStringForm $policyIdForm,
        private readonly array $assetNameForms,
    ) {}

    /**
     * A bundle built rather than decoded: policy id as raw bytes, assets as name bytes to quantity.
     *
     * Quantities arrive as decimal strings because a ledger quantity is a uint64 and PHP's integer stops short of
     * that. Passing one through an int on the way in would truncate it before this class ever saw it.
     *
     * @param  list<array{string, string}>  $assets  asset name bytes, quantity as a decimal string
     */
    public static function of(string $policyId, array $assets): self
    {
        if (strlen($policyId) !== 28) {
            throw new DecodeException(sprintf(
                'A policy id is 28 bytes, got %d.',
                strlen($policyId)
            ));
        }

        if ($assets === []) {
            throw new DecodeException('A policy carries no assets.');
        }

        $decoded = [];
        foreach ($assets as [$name, $quantity]) {
            if (strlen($name) > 32) {
                throw new DecodeException(sprintf('An asset name is at most 32 bytes, got %d.', strlen($name)));
            }

            $decoded[] = [$name, CborInteger::of($quantity)];
        }

        return new self(
            $policyId,
            MapForm::definite(),
            $decoded,
            ByteStringForm::shortest(),
            array_fill(0, count($decoded), ByteStringForm::shortest()),
        );
    }

    /**
     * The same assets, ordered the way canonical CBOR orders map keys, with duplicate names refused.
     *
     * MultiAsset::canonical calls this for every policy it holds. The ordering rule is there; what is here is the
     * duplicate check, which has to happen per policy because an asset is only named inside one.
     */
    public function canonical(): self
    {
        $seen = [];
        foreach ($this->assets as [$name]) {
            if (isset($seen[$name])) {
                throw new DecodeException(sprintf(
                    'The asset name %s appears twice under policy %s.',
                    $name === '' ? '(empty)' : bin2hex($name),
                    $this->policyIdHex()
                ));
            }

            $seen[$name] = true;
        }

        $ordered = $this->assets;
        usort($ordered, static fn (array $a, array $b): int => MultiAsset::compareKeys($a[0], $b[0]));

        return new self(
            $this->policyId,
            MapForm::definite(),
            $ordered,
            ByteStringForm::shortest(),
            array_fill(0, count($ordered), ByteStringForm::shortest()),
        );
    }

    public static function fromCbor(CborValue $policyId, CborValue $assets, string $context, bool $signed): self
    {
        $policy = Shape::bytes($policyId, $context.' policy id', 28);
        [$form, $entries] = MapForm::unwrap($assets, $context.' asset map');

        $decoded = [];
        $nameForms = [];
        foreach ($entries as [$name, $quantity]) {
            $assetName = Shape::boundedBytes($name, $context.' asset name', 0, 32);
            $decoded[] = [
                $assetName,
                $signed
                    ? CborInteger::fromCbor($quantity, $context.' quantity')
                    : CborInteger::unsignedFromCbor($quantity, $context.' quantity'),
            ];
            $nameForms[] = ByteStringForm::of($name);
        }

        if ($decoded === []) {
            throw new DecodeException(sprintf('%s: a policy carries no assets.', $context));
        }

        return new self($policy, $form, $decoded, ByteStringForm::of($policyId), $nameForms);
    }

    public function toCbor(): CborValue
    {
        $entries = [];
        foreach ($this->assets as $index => [$name, $quantity]) {
            $entries[] = [$this->assetNameForms[$index]->wrap($name), $quantity->toCbor()];
        }

        return $this->form->wrap($entries);
    }

    /**
     * The policy id as the map key it is written as, at the head it arrived in.
     *
     * A multiasset map is keyed by policy id, so the key is part of this bundle rather than of the map that holds
     * it, and so is the head it was written with.
     */
    public function policyIdKey(): CborValue
    {
        return $this->policyIdForm->wrap($this->policyId);
    }

    public function policyIdHex(): string
    {
        return bin2hex($this->policyId);
    }

    /**
     * @return list<array{string, CborInteger}>
     */
    public function assets(): array
    {
        return $this->assets;
    }

    public function count(): int
    {
        return count($this->assets);
    }
}
