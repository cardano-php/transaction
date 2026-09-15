<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Exception\ArithmeticException;

/**
 * The minimum fee a transaction has to carry.
 *
 * txFeeFixed + txFeePerByte * size, where size is the length of the **fully witnessed** transaction.
 *
 * The word that does the work there is "witnessed". The fee is charged on the bytes that go to the node, and that
 * includes the witness set: the vkey witnesses, the native scripts, the auxiliary data. A fee computed over the
 * body alone is short by about a hundred bytes per signature, which at the current txFeePerByte is a few thousand
 * lovelace, and the node refuses the transaction rather than accepting it and charging the difference. Since a
 * transaction cannot be witnessed until it is built and cannot be built until its fee is known, the witnesses are
 * sized before they exist; WitnessPlan does that sizing and FeeFixedPoint drives the loop.
 *
 * Conway added a second head of charge that is easy to miss, because a transaction that does not use reference
 * scripts pays nothing under it and every test built from such transactions passes without it. A transaction that
 * does use them is charged on a rising tier: the first 25600 bytes at minFeeRefScriptCostPerByte, the next 25600 at
 * 1.2 times that, and so on. That multiplier is a rational, and it is applied to an exact accumulator and floored
 * once at the end; computing it in a float gives a number that is right nearly always, which is the worst place for
 * a rounding error to live. It is computed here as an exact fraction over ext-bcmath.
 *
 * **Which size.** A caller passes the size; nothing here measures a transaction on the caller's behalf. That is not
 * indecision. Across all 3773 mainnet transactions in this build's corpus, the size the chain recorded for a
 * transaction is exactly one byte less than the length of the CBOR a provider returns for it, without exception,
 * and 175 of those transactions paid a fee below what the CBOR length implies. A builder that measured its own
 * bytes and believed a provider's bytes were the same measurement would be right most of the time and wrong in a
 * way that reads as the ledger being inconsistent.
 *
 * What this build does is charge on its own full serialization, which is at most one byte over what the ledger
 * asks and never under. That costs one txFeePerByte, currently forty-four lovelace, and buys not having to depend
 * on a one-byte relationship that was measured through one provider on one afternoon. Reading a fee back off the
 * chain is the other direction, and there the recorded size is the number to use.
 */
final class FeeCalculator
{
    /** The size of one reference script tier, in bytes. A protocol constant, not a parameter. */
    public const REF_SCRIPT_TIER_BYTES = 25600;

    /** The tier multiplier, 1.2, as the exact fraction it is. */
    private const TIER_MULTIPLIER_NUMERATOR = '6';

    private const TIER_MULTIPLIER_DENOMINATOR = '5';

    private function __construct(private readonly LedgerParameters $parameters) {}

    public static function under(LedgerParameters $parameters): self
    {
        return new self($parameters);
    }

    /**
     * The minimum fee for a transaction of this many bytes, carrying this many bytes of reference script.
     *
     * @param  int  $referenceScriptBytes  the total size of every reference script the transaction reaches, which
     *                                     lives in the outputs the inputs point at and so cannot be read from the
     *                                     transaction alone. Nought for every transaction this build produces.
     */
    public function forSize(int $size, int $referenceScriptBytes = 0): Natural
    {
        if ($size <= 0) {
            throw new ArithmeticException(sprintf('A transaction cannot be %d bytes long.', $size));
        }

        $base = $this->parameters->txFeeFixed->plus($this->parameters->txFeePerByte->times($size));

        return $referenceScriptBytes === 0
            ? $base
            : $base->plus($this->referenceScriptFee($referenceScriptBytes));
    }

    /**
     * The minimum fee for a transaction, measured on the bytes it would be submitted as.
     *
     * The bytes handed in have to be the witnessed ones. An unsigned skeleton produces a number that is confidently
     * too small, so FeeFixedPoint exists to make the witnessed size available before the witnesses do.
     *
     * There is deliberately no overload taking a decoded Transaction. Measuring one of those measures the CBOR a
     * provider returned, which is not the size that transaction was charged on, and the difference is exactly the
     * trap described above.
     */
    public function forBytes(string $bytes, int $referenceScriptBytes = 0): Natural
    {
        return $this->forSize(strlen($bytes), $referenceScriptBytes);
    }

    /**
     * Conway's tiered charge for reference scripts, computed exactly.
     *
     * The accumulator is a fraction. Each full tier of 25600 bytes adds tierBytes * price to it and multiplies the
     * price by 6/5; the remainder adds remainder * price. The denominator is therefore 5^(number of full tiers),
     * and the result is floored once, at the end, exactly as the ledger does it. Flooring per tier instead would
     * come out low, and a fee that is low by any amount is a transaction the node refuses.
     */
    public function referenceScriptFee(int $referenceScriptBytes): Natural
    {
        if ($referenceScriptBytes < 0) {
            throw new ArithmeticException(sprintf(
                'A reference script cannot be %d bytes long.',
                $referenceScriptBytes
            ));
        }

        if ($referenceScriptBytes === 0) {
            return Natural::zero();
        }

        $price = $this->parameters->refScriptCostPerByte()->value;

        $accumulatorNumerator = '0';
        $denominator = '1';
        $remaining = $referenceScriptBytes;

        while ($remaining >= self::REF_SCRIPT_TIER_BYTES) {
            // acc += tierBytes * price, with acc over the running denominator and price over the same one.
            $accumulatorNumerator = bcadd(
                $accumulatorNumerator,
                bcmul((string) self::REF_SCRIPT_TIER_BYTES, $price, 0),
                0
            );

            // price *= 6/5, which means multiplying everything already accumulated by 5 and the price by 6.
            $accumulatorNumerator = bcmul($accumulatorNumerator, self::TIER_MULTIPLIER_DENOMINATOR, 0);
            $denominator = bcmul($denominator, self::TIER_MULTIPLIER_DENOMINATOR, 0);
            $price = bcmul($price, self::TIER_MULTIPLIER_NUMERATOR, 0);

            $remaining -= self::REF_SCRIPT_TIER_BYTES;
        }

        $accumulatorNumerator = bcadd($accumulatorNumerator, bcmul((string) $remaining, $price, 0), 0);

        return Natural::of(bcdiv($accumulatorNumerator, $denominator, 0));
    }

    /**
     * Whether a transaction of this size is inside maxTxSize.
     *
     * Kept next to the fee because they are asked together and because the failure modes rhyme: a transaction over
     * the size limit is refused by the node in the same breath as one carrying too small a fee, and both are
     * cheaper to find here than in a submission response.
     */
    public function fitsMaxTxSize(int $size): bool
    {
        return $this->parameters->maxTxSize->isAtLeast(Natural::of($size));
    }
}
