<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;

/**
 * What a selection is not allowed to exceed.
 *
 * Only one limit so far, and it is a real one. Every input costs bytes in the body and a whole witness in the
 * witness set, so a selection that gathers sixty dusty outputs to make up a payment can build a transaction that no
 * fee will make valid, because it is over maxTxSize. Finding that out here, with the count in the message, is
 * cheaper than finding it out from a node.
 *
 * The limit is not derived from maxTxSize on purpose. How many inputs fit depends on how many outputs and how much
 * metadata the transaction also carries, which selection does not know, so the number is the caller's to set from
 * what it is building.
 */
final class SelectionLimits
{
    private function __construct(public readonly ?int $maxInputs) {}

    public static function none(): self
    {
        return new self(null);
    }

    public static function maxInputs(int $maxInputs): self
    {
        if ($maxInputs < 1) {
            throw new SelectionException(sprintf('A transaction needs at least one input, not %d.', $maxInputs));
        }

        return new self($maxInputs);
    }

    public function check(int $inputCount): void
    {
        if ($this->maxInputs !== null && $inputCount > $this->maxInputs) {
            throw new SelectionException(sprintf(
                'Reaching the target took %d inputs and the limit is %d.',
                $inputCount,
                $this->maxInputs
            ));
        }
    }
}
