<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

/**
 * What selection chose, and what is left over after the target is met.
 *
 * The surplus is the input to change, and it is a bag rather than a lovelace figure because it is nearly never only
 * lovelace. Pay someone one of a token out of an output holding four hundred of it, and the other three hundred and
 * ninety-nine come back as change, along with everything else that output happened to be carrying. Losing that is
 * not an accounting error, it is burning somebody's tokens, and it is the single failure a change implementation
 * exists to prevent.
 */
final class SelectionResult
{
    /**
     * @param  list<Utxo>  $inputs
     */
    public function __construct(
        public readonly array $inputs,
        public readonly ValueBag $selected,
        public readonly ValueBag $target,
        public readonly ValueBag $surplus,
    ) {}

    public function inputCount(): int
    {
        return count($this->inputs);
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return array_map(static fn (Utxo $utxo): string => $utxo->reference(), $this->inputs);
    }
}
