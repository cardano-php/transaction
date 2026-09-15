<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Exception\ArithmeticException;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;

/**
 * What an output has to hold before the ledger will let it exist.
 *
 * (160 + the size of the serialized output) * utxoCostPerByte.
 *
 * The 160 is not part of the output. It is the ledger's charge for the entry the node keeps in its own UTxO map:
 * the input reference that points at this output, the map overhead around it, and the memory a running node gives
 * up for as long as the output is unspent. It is a constant in the protocol, not a parameter, so it is written here
 * as a constant rather than taken from a parameter set that has no field for it.
 *
 * Two things follow from the size being the size of the *serialized* output, and both are easy to get wrong.
 *
 * The first is that the asset count is not the input. An output holding ten assets under one policy is smaller than
 * one holding ten assets under ten policies, because the policy id is 28 bytes and is written once per policy, and
 * an asset with a one-byte name is smaller than one with a thirty-two byte name. A formula that counted assets
 * would be wrong in both directions.
 *
 * The second is that the coin is inside the thing being measured. Raise the coin from 999999 to 1000000 and the
 * CBOR integer holding it goes from four bytes to five, the output grows by one byte, and the minimum goes up by
 * another utxoCostPerByte. So finding the smallest coin an output can hold is a fixed point, not a division, and
 * forValue() below is what walks it.
 */
final class MinimumUtxo
{
    /**
     * The ledger's constant overhead per UTxO entry, in bytes. Not a protocol parameter and not derived from one.
     */
    public const ENTRY_OVERHEAD_BYTES = 160;

    /**
     * The fixed point below converges in two or three passes because each pass can only widen the coin, and the coin
     * has four widths above one byte. The cap is there to turn a bug in the encoder into a failure rather than a
     * hang.
     */
    private const MAX_PASSES = 8;

    private function __construct(private readonly LedgerParameters $parameters) {}

    public static function under(LedgerParameters $parameters): self
    {
        return new self($parameters);
    }

    /**
     * The minimum for an output exactly as it is written, coin included.
     *
     * This is the form the ledger's own rule takes, and it is the form the corpus test asserts against: for every
     * output the chain accepted, this number is at or below the coin that output actually holds.
     */
    public function forOutput(TransactionOutput $output): Natural
    {
        return $this->forSerializedSize(strlen($output->encode()));
    }

    public function forSerializedSize(int $bytes): Natural
    {
        if ($bytes <= 0) {
            throw new ArithmeticException(sprintf('An output cannot be %d bytes long.', $bytes));
        }

        return Natural::of($bytes + self::ENTRY_OVERHEAD_BYTES)->times($this->parameters->utxoCostPerByte);
    }

    /**
     * The smallest coin this address and this asset bundle can be written with, found by fixed point.
     *
     * Start from a coin of nought, measure, raise the coin to the minimum that measurement implies, and measure
     * again. Each pass can only widen the integer holding the coin, never narrow it, so the sequence is monotone and
     * stops. When two passes running give the same number, that number is a coin whose own encoding is already paid
     * for, which is the property the ledger checks.
     */
    public function forValue(string $address, Value $value): Natural
    {
        $coin = Natural::zero();

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $output = TransactionOutput::create($address, $value->withCoin($coin->value));
            $required = $this->forOutput($output);

            if ($required->equals($coin)) {
                return $coin;
            }

            if ($required->isLessThan($coin)) {
                // Narrowing would mean the encoder wrote a smaller number in more bytes. Nothing does that, and
                // treating it as convergence would return a coin the ledger rejects.
                throw new ArithmeticException(sprintf(
                    'The minimum fell from %s to %s when the coin was raised; the output encoding is not monotone.',
                    $coin->value,
                    $required->value
                ));
            }

            $coin = $required;
        }

        throw new ArithmeticException(sprintf(
            'The minimum UTxO for this output did not settle in %d passes.',
            self::MAX_PASSES
        ));
    }

    /**
     * The output, with its coin raised to the minimum if it is short and left alone if it is not.
     *
     * Raising is the only correction offered. Lowering a coin somebody asked to send is not this layer's decision.
     */
    public function fund(string $address, Value $value): TransactionOutput
    {
        $required = $this->forValue($address, $value);
        $output = TransactionOutput::create($address, $value);
        $held = Natural::of($output->value->coin->value);

        return $held->isAtLeast($required) ? $output : $output->withValue($value->withCoin($required->value));
    }

    public function isSatisfiedBy(TransactionOutput $output): bool
    {
        return Natural::of($output->value->coin->value)->isAtLeast($this->forOutput($output));
    }
}
