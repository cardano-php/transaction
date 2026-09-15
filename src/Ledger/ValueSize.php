<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;

/**
 * How large a value is, and whether the ledger will carry it.
 *
 * maxValueSize is stated in bytes of the serialized value, and the check has to be made on those bytes. The shortcut
 * everyone reaches for is an asset count, and it is wrong in both directions at once.
 *
 * Too permissive: asset names run to thirty-two bytes and policy ids are twenty-eight, so a hundred assets under a
 * hundred policies with long names is thousands of bytes, and a count-based check waves it through.
 *
 * Too strict: a hundred assets under one policy with short names is a fraction of that, perfectly legal, and a
 * count-based check refuses to build it.
 *
 * Quantities move the answer as well, because a quantity of 1 occupies one byte and a quantity near 2^64 occupies
 * nine. None of this is derivable from a count, so nothing here derives it from one. Every answer comes from
 * encoding the value and taking its length.
 */
final class ValueSize
{
    private function __construct(private readonly LedgerParameters $parameters) {}

    public static function under(LedgerParameters $parameters): self
    {
        return new self($parameters);
    }

    /** The serialized length of the value, in bytes. */
    public static function of(Value $value): int
    {
        return strlen($value->encode());
    }

    /** The serialized value carried by an output, which is the part maxValueSize is about. */
    public static function ofOutput(TransactionOutput $output): int
    {
        return self::of($output->value);
    }

    /**
     * Whether this value is inside the limit.
     *
     * The packer asks this after every asset it adds, not after every ten, and not from an estimate. That is the
     * whole of "checked while packing rather than assumed from a count".
     */
    public function fits(Value $value): bool
    {
        return $this->parameters->maxValueSize->isAtLeast(Natural::of(self::of($value)));
    }

    public function outputFits(TransactionOutput $output): bool
    {
        return $this->fits($output->value);
    }

    /** Bytes still available under the limit, or nought when the value is already at or over it. */
    public function headroom(Value $value): int
    {
        $used = self::of($value);
        $limit = $this->parameters->maxValueSize->toInt();

        return $used >= $limit ? 0 : $limit - $used;
    }

    public function limit(): Natural
    {
        return $this->parameters->maxValueSize;
    }
}
