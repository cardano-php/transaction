<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Primitives\TransactionOutput;

/**
 * One unspent output, in the shape selection needs it.
 *
 * Deliberately not a provider's response. Koios nests assets under a policy in `asset_list` and Blockfrost writes
 * them flat in `amount`; both change; neither is a model. Selection needs four things -- where the output is, what
 * it holds, whose address it sits at, and how big it was when it was written -- and everything else a provider
 * sends is for somebody else.
 *
 * This is also why this class takes a normalized UTxO rather than reaching for one. The branch that owns the
 * provider layer defines its own; when the two land together, the adapter between them is a loop and a constructor
 * call, and nothing in here has to change to accept it.
 */
final class Utxo
{
    private function __construct(
        public readonly string $transactionId,
        public readonly int $index,
        public readonly string $address,
        public readonly ValueBag $value,
    ) {}

    public static function of(string $transactionIdHex, int $index, string $address, ValueBag $value): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $transactionIdHex) !== 1) {
            throw new SelectionException(
                'A transaction id is 64 lowercase hex characters, got: '.$transactionIdHex
            );
        }

        if ($index < 0) {
            throw new SelectionException(sprintf('An output index cannot be %d.', $index));
        }

        if ($address === '') {
            throw new SelectionException('An unspent output sits at an address.');
        }

        return new self($transactionIdHex, $index, $address, $value);
    }

    public static function fromOutput(string $transactionIdHex, int $index, TransactionOutput $output): self
    {
        return self::of($transactionIdHex, $index, $output->address, ValueBag::fromValue($output->value));
    }

    /**
     * The identity of this output, and the order selection falls back on.
     *
     * Ties have to break somewhere, and they have to break the same way every run: a selection that depends on hash
     * ordering produces a different transaction each time it is asked, which makes the fee fixed point untestable
     * and a failed submission unreproducible.
     */
    public function reference(): string
    {
        return $this->transactionId.'#'.$this->index;
    }

    public function coin(): Natural
    {
        return $this->value->coin;
    }

    public function quantityOf(AssetId $asset): Natural
    {
        return $this->value->quantityOf($asset);
    }

    public function holds(AssetId $asset): bool
    {
        return $this->value->holds($asset);
    }

    public function isPureAda(): bool
    {
        return ! $this->value->hasAssets();
    }

    public function assetCount(): int
    {
        return $this->value->assetCount();
    }
}
