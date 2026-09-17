<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Exception\ArithmeticException;

/**
 * Settling a fee that is inside the thing it is charged on.
 *
 * The fee is a field of the body. Raise it from 170000 to 180000 and nothing moves, because both are four-byte CBOR
 * integers. Raise it from 65000 to 70000 and the integer goes from three bytes to five, the transaction is two bytes
 * longer, and the minimum fee for a transaction that long is higher than the fee just written into it. A builder
 * that computes the fee once, writes it, and submits, produces a transaction the node refuses about once in every
 * few hundred, on a boundary nobody thinks to test.
 *
 * Two ways out, and both are here because they suit different callers.
 *
 * Iterate. Compute the fee, write it, measure again, repeat until the fee stops moving. Size is non-decreasing in
 * the fee and the fee is non-decreasing in size, and there are only five widths a CBOR unsigned integer can take,
 * so the sequence can step up at most a handful of times and then stops. This is what solve() does, and it produces
 * the smallest fee that is correct for the transaction carrying it.
 *
 * Pad. Write the fee at the full eight-byte width from the start, so the field never changes size and one pass is
 * enough. This costs up to seven bytes, which at the current txFeePerByte is about three hundred lovelace, and it
 * removes the loop. padded() below is the arithmetic for it; CborInteger::widest() is how the field gets written.
 *
 * Neither is fit to build a transaction on its own. Both take a callback that turns a candidate fee into the bytes
 * that would be submitted, because owning the body builder is a later step's job, and because the callback is what
 * makes the fully-witnessed requirement the caller's to satisfy visibly rather than this class's to assume.
 */
final class FeeFixedPoint
{
    /**
     * A CBOR unsigned integer has five widths. A run that steps up more times than that is not converging on
     * anything and is a bug somewhere below, so it is reported rather than retried forever.
     */
    private const MAX_PASSES = 8;

    private function __construct(private readonly FeeCalculator $calculator) {}

    public static function under(LedgerParameters $parameters): self
    {
        return new self(FeeCalculator::under($parameters));
    }

    /**
     * The smallest fee this transaction can carry and still cover itself.
     *
     * @param  callable(Natural): string  $render  given a candidate fee, the bytes of the FULLY WITNESSED
     *                                             transaction carrying it. Witnesses of the right size holding
     *                                             zeroes are what WitnessPlan is for; a skeleton with no witness set
     *                                             produces a fee that is confidently too small.
     * @param  int  $referenceScriptBytes  total reference script size, nought for everything this build produces
     */
    public function solve(callable $render, int $referenceScriptBytes = 0): FeeSolution
    {
        $fee = Natural::zero();

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $bytes = $render($fee);
            $required = $this->calculator->forSize(strlen($bytes), $referenceScriptBytes);

            if ($required->equals($fee)) {
                return new FeeSolution($fee, strlen($bytes), $pass, false);
            }

            if ($required->isLessThan($fee)) {
                // Writing a larger fee produced a smaller transaction. Nothing in CBOR does that, so this is the
                // callback rendering something other than the fee it was handed, and stopping here is the only way
                // that surfaces rather than settling on a number nobody checked.
                throw new ArithmeticException(sprintf(
                    'Raising the fee to %s lowered the requirement to %s; the transaction being measured is not the '
                    .'one the fee was written into.',
                    $fee->value,
                    $required->value
                ));
            }

            $fee = $required;
        }

        throw new ArithmeticException(sprintf('The fee did not settle in %d passes.', self::MAX_PASSES));
    }

    /**
     * The fee for a transaction whose fee field is written at full width, settled in one pass.
     *
     * @param  callable(Natural): string  $render  as above, except that the fee is written with
     *                                             CborInteger::widest() so the field's length never changes
     */
    public function padded(callable $render, int $referenceScriptBytes = 0): FeeSolution
    {
        $bytes = $render(Natural::zero());
        $size = strlen($bytes);
        $fee = $this->calculator->forSize($size, $referenceScriptBytes);

        $confirmation = $render($fee);
        if (strlen($confirmation) !== $size) {
            throw new ArithmeticException(sprintf(
                'The padded fee field changed the transaction size from %d to %d bytes; the field was not written at '
                .'full width.',
                $size,
                strlen($confirmation)
            ));
        }

        return new FeeSolution($fee, $size, 1, true);
    }

    /**
     * The width in bytes of the CBOR integer a fee is written in, which is the whole of why the loop exists.
     */
    public static function feeFieldBytes(Natural $fee): int
    {
        return strlen(CborCodec::encode(CborInteger::of($fee->value)->toCbor()));
    }
}
