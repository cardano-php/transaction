<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Exception\ParameterException;

/**
 * The handful of protocol parameters this arithmetic reads, handed in rather than fetched.
 *
 * This library does not talk to a provider and does not know what a network is. Where the numbers come from is the
 * application's business: an epoch parameter service, a cached response, a fixture in a test. Taking them as a value
 * is also what lets the fee and selection work sit on a branch that does not carry the provider layer, and compose
 * with it unchanged when both land, because the field names here are the names that layer already translates into.
 *
 * Six numbers, and no defaults for any of them. Every parameter here changes at a hard fork and several have changed
 * more than once. A pinned default is a number that is right until it is quietly wrong, and the failure it produces
 * is either a transaction the node refuses or an output funded out of the operator's own balance.
 */
final class LedgerParameters
{
    /**
     * The constant added to the size-proportional part of every transaction fee. The ledger's own name for it; Koios
     * reports it as min_fee_b.
     */
    public readonly Natural $txFeeFixed;

    /** Lovelace per byte of the fully witnessed transaction. Koios reports it as min_fee_a. */
    public readonly Natural $txFeePerByte;

    /** Lovelace per byte of a serialized output, before the 160 byte overhead. Koios: coins_per_utxo_size. */
    public readonly Natural $utxoCostPerByte;

    /** The largest a serialized value may be, in bytes. Koios: max_val_size. */
    public readonly Natural $maxValueSize;

    /** The largest a serialized transaction may be, in bytes. Koios: max_tx_size. */
    public readonly Natural $maxTxSize;

    /**
     * Lovelace per byte of reference script, the base price of Conway's tiered surcharge. Absent before Conway, which
     * is why it is the one optional field: a transaction using no reference scripts pays nothing under this head and
     * does not need the number at all.
     */
    public readonly ?Natural $minFeeRefScriptCostPerByte;

    private const REQUIRED = [
        'txFeeFixed',
        'txFeePerByte',
        'utxoCostPerByte',
        'maxValueSize',
        'maxTxSize',
    ];

    /**
     * Parameters that cannot be zero, with the reason. The two fee parameters are absent from this list on purpose:
     * a private network may legitimately charge nothing, and refusing that would make this library untestable
     * against one. A zero utxoCostPerByte would make every minimum come out at nothing, and a zero size limit would
     * make every transaction too large, and no network has ever meant either.
     */
    private const MUST_BE_POSITIVE = ['utxoCostPerByte', 'maxValueSize', 'maxTxSize'];

    public function __construct(
        Natural|int|string $txFeeFixed,
        Natural|int|string $txFeePerByte,
        Natural|int|string $utxoCostPerByte,
        Natural|int|string $maxValueSize,
        Natural|int|string $maxTxSize,
        Natural|int|string|null $minFeeRefScriptCostPerByte = null,
    ) {
        $this->txFeeFixed = self::read('txFeeFixed', $txFeeFixed);
        $this->txFeePerByte = self::read('txFeePerByte', $txFeePerByte);
        $this->utxoCostPerByte = self::read('utxoCostPerByte', $utxoCostPerByte);
        $this->maxValueSize = self::read('maxValueSize', $maxValueSize);
        $this->maxTxSize = self::read('maxTxSize', $maxTxSize);
        $this->minFeeRefScriptCostPerByte = $minFeeRefScriptCostPerByte === null
            ? null
            : self::read('minFeeRefScriptCostPerByte', $minFeeRefScriptCostPerByte);

        foreach (self::MUST_BE_POSITIVE as $name) {
            if ($this->{$name}->isZero()) {
                throw new ParameterException(sprintf('%s is zero; no network has ever meant that.', $name));
            }
        }
    }

    /**
     * Build from an array keyed by the names above.
     *
     * A key it has never heard of is ignored, because the parameter set grows at every hard fork and a reader that
     * refuses an unknown field stops working the day the fork lands, over a field it would not have read. A key the
     * arithmetic needs, missing or null, is fatal.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        foreach (self::REQUIRED as $name) {
            if (! array_key_exists($name, $values) || $values[$name] === null) {
                throw new ParameterException(sprintf('The protocol parameter %s is missing.', $name));
            }
        }

        return new self(
            txFeeFixed: $values['txFeeFixed'],
            txFeePerByte: $values['txFeePerByte'],
            utxoCostPerByte: $values['utxoCostPerByte'],
            maxValueSize: $values['maxValueSize'],
            maxTxSize: $values['maxTxSize'],
            minFeeRefScriptCostPerByte: $values['minFeeRefScriptCostPerByte'] ?? null,
        );
    }

    /**
     * Build from a Koios /epoch_params row, under the names Koios states them in.
     *
     * The translation lives here rather than being pushed onto the caller only because the names are the whole of
     * the difference, and getting min_fee_a and min_fee_b the wrong way round is silent: the transaction still
     * builds, and pays a fee wrong by four orders of magnitude.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromKoiosEpochParams(array $row): self
    {
        return self::fromArray([
            'txFeeFixed' => $row['min_fee_b'] ?? null,
            'txFeePerByte' => $row['min_fee_a'] ?? null,
            'utxoCostPerByte' => $row['coins_per_utxo_size'] ?? null,
            'maxValueSize' => $row['max_val_size'] ?? null,
            'maxTxSize' => $row['max_tx_size'] ?? null,
            'minFeeRefScriptCostPerByte' => $row['min_fee_ref_script_cost_per_byte'] ?? null,
        ]);
    }

    /**
     * The reference script price, or a refusal saying which parameter is missing.
     *
     * A transaction that spends a reference script under a set of parameters that does not carry the price cannot be
     * costed, and quoting it the base fee alone would under-quote it.
     */
    public function refScriptCostPerByte(): Natural
    {
        return $this->minFeeRefScriptCostPerByte ?? throw new ParameterException(
            'This transaction uses reference scripts, and minFeeRefScriptCostPerByte is not among the parameters '
            .'given. Conway charges for them on a tier of its own and the base fee alone would be an under-quote.'
        );
    }

    private static function read(string $name, Natural|int|string $value): Natural
    {
        if ($value instanceof Natural) {
            return $value;
        }

        try {
            return Natural::of($value);
        } catch (\Cardano\Transaction\Exception\ArithmeticException $e) {
            throw new ParameterException(
                sprintf('The protocol parameter %s is not a non-negative integer: %s', $name, $e->getMessage()),
                0,
                $e
            );
        }
    }
}
