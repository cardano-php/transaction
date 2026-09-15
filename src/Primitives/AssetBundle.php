<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;
use CBOR\ByteStringObject;
use CBOR\CBORObject;

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
     */
    private function __construct(
        public readonly string $policyId,
        private readonly MapForm $form,
        private readonly array $assets,
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

        return new self($policyId, MapForm::definite(), $decoded);
    }

    public static function fromCbor(CBORObject $policyId, CBORObject $assets, string $context, bool $signed): self
    {
        $policy = Shape::bytes($policyId, $context.' policy id', 28);
        [$form, $entries] = MapForm::unwrap($assets, $context.' asset map');

        $decoded = [];
        foreach ($entries as [$name, $quantity]) {
            $assetName = Shape::boundedBytes($name, $context.' asset name', 0, 32);
            $decoded[] = [
                $assetName,
                $signed
                    ? CborInteger::fromCbor($quantity, $context.' quantity')
                    : CborInteger::unsignedFromCbor($quantity, $context.' quantity'),
            ];
        }

        if ($decoded === []) {
            throw new DecodeException(sprintf('%s: a policy carries no assets.', $context));
        }

        return new self($policy, $form, $decoded);
    }

    public function toCbor(): CBORObject
    {
        $entries = [];
        foreach ($this->assets as [$name, $quantity]) {
            $entries[] = [ByteStringObject::create($name), $quantity->toCbor()];
        }

        return $this->form->wrap($entries);
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
