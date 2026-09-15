<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

/**
 * What the fee settled at, and what it took to get there.
 *
 * The passes and the padding flag are not decoration. A transaction that needed four passes is one whose fee
 * crossed an encoding width, which is the case that breaks a builder that computes the fee once, and a run that
 * never produces one is a run that has not exercised the loop.
 */
final class FeeSolution
{
    public function __construct(
        public readonly Natural $fee,
        public readonly int $transactionBytes,
        public readonly int $passes,
        public readonly bool $padded,
    ) {}
}
