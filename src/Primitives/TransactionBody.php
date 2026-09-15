<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;
use CBOR\ByteStringObject;
use CBOR\CBORObject;

/**
 * The part of a transaction the hash is taken over.
 *
 * Fields are read in the order they arrived and written back in that order, because the ledger hashes the bytes it
 * was given rather than a canonical arrangement of them. The fields this step owns are rebuilt from the model; the
 * ones it does not -- certificates, withdrawals, the Shelley update field and the Conway governance fields -- are
 * carried through as decoded, which is exactly as much as this step claims about them.
 */
final class TransactionBody
{
    public const FIELD_INPUTS = 0;

    public const FIELD_OUTPUTS = 1;

    public const FIELD_FEE = 2;

    public const FIELD_TTL = 3;

    public const FIELD_CERTIFICATES = 4;

    public const FIELD_WITHDRAWALS = 5;

    public const FIELD_UPDATE = 6;

    public const FIELD_AUXILIARY_DATA_HASH = 7;

    public const FIELD_VALIDITY_INTERVAL_START = 8;

    public const FIELD_MINT = 9;

    public const FIELD_SCRIPT_DATA_HASH = 11;

    public const FIELD_COLLATERAL = 13;

    public const FIELD_REQUIRED_SIGNERS = 14;

    public const FIELD_NETWORK_ID = 15;

    public const FIELD_COLLATERAL_RETURN = 16;

    public const FIELD_TOTAL_COLLATERAL = 17;

    public const FIELD_REFERENCE_INPUTS = 18;

    public const FIELD_VOTING_PROCEDURES = 19;

    public const FIELD_PROPOSAL_PROCEDURES = 20;

    public const FIELD_CURRENT_TREASURY_VALUE = 21;

    public const FIELD_DONATION = 22;

    /**
     * Ten and twelve were never assigned. A field outside this list is a transaction shape this decoder has not been
     * told about, and reading past it would mean guessing at what the ledger did with it.
     */
    private const FIELDS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22];

    private const REQUIRED_FIELDS = [0, 1, 2];

    /**
     * @param  array<int, CBORObject>  $fields
     * @param  array<int, CborInteger>  $keys
     * @param  array<int, list<TransactionInput>>  $inputSets
     * @param  array<int, SequenceForm>  $inputSetForms
     * @param  list<TransactionOutput>  $outputs
     * @param  list<string>  $requiredSigners
     */
    private function __construct(
        private readonly MapForm $form,
        private readonly array $fields,
        private readonly array $keys,
        private readonly array $inputSets,
        private readonly array $inputSetForms,
        private readonly array $outputs,
        private readonly SequenceForm $outputForm,
        private readonly CborInteger $fee,
        private readonly ?CborInteger $ttl,
        private readonly ?CborInteger $validityIntervalStart,
        private readonly ?string $auxiliaryDataHash,
        private readonly ?string $scriptDataHash,
        private readonly ?MultiAsset $mint,
        private readonly ?CborInteger $networkId,
        private readonly ?CborInteger $totalCollateral,
        private readonly ?TransactionOutput $collateralReturn,
        private readonly array $requiredSigners,
        private readonly ?SequenceForm $requiredSignerForm,
    ) {}

    public static function fromCbor(CBORObject $object, string $context = 'body'): self
    {
        [$form, $fields, $keys] = MapForm::unwrapIntKeyed($object, $context, self::FIELDS);

        foreach (self::REQUIRED_FIELDS as $required) {
            if (! isset($fields[$required])) {
                throw new DecodeException(sprintf('%s: field %d is missing.', $context, $required));
            }
        }

        $inputSets = [];
        $inputSetForms = [];
        foreach ([self::FIELD_INPUTS, self::FIELD_COLLATERAL, self::FIELD_REFERENCE_INPUTS] as $field) {
            if (! isset($fields[$field])) {
                continue;
            }

            [$setForm, $items] = SequenceForm::unwrap($fields[$field], sprintf('%s field %d', $context, $field));
            $inputs = [];
            foreach ($items as $index => $item) {
                $inputs[] = TransactionInput::fromCbor($item, sprintf('%s field %d input %d', $context, $field, $index));
            }
            $inputSets[$field] = $inputs;
            $inputSetForms[$field] = $setForm;
        }

        if ($inputSets[self::FIELD_INPUTS] === []) {
            throw new DecodeException(sprintf('%s: no inputs.', $context));
        }

        [$outputForm, $outputItems] = SequenceForm::unwrap(
            $fields[self::FIELD_OUTPUTS],
            $context.' outputs',
            allowSetTag: false
        );
        $outputs = [];
        foreach ($outputItems as $index => $item) {
            $outputs[] = TransactionOutput::fromCbor($item, sprintf('%s output %d', $context, $index));
        }

        $requiredSigners = [];
        $requiredSignerForm = null;
        if (isset($fields[self::FIELD_REQUIRED_SIGNERS])) {
            [$requiredSignerForm, $items] = SequenceForm::unwrap(
                $fields[self::FIELD_REQUIRED_SIGNERS],
                $context.' required signers'
            );
            foreach ($items as $index => $item) {
                $requiredSigners[] = Shape::bytes($item, sprintf('%s required signer %d', $context, $index), 28);
            }
        }

        $networkId = isset($fields[self::FIELD_NETWORK_ID])
            ? CborInteger::unsignedFromCbor($fields[self::FIELD_NETWORK_ID], $context.' network id')
            : null;

        if ($networkId !== null && ! $networkId->equalsInt(0) && ! $networkId->equalsInt(1)) {
            throw new DecodeException(sprintf('%s: network id is %s, expected 0 or 1.', $context, $networkId->value));
        }

        return new self(
            $form,
            $fields,
            $keys,
            $inputSets,
            $inputSetForms,
            $outputs,
            $outputForm,
            CborInteger::unsignedFromCbor($fields[self::FIELD_FEE], $context.' fee'),
            isset($fields[self::FIELD_TTL])
                ? CborInteger::unsignedFromCbor($fields[self::FIELD_TTL], $context.' ttl')
                : null,
            isset($fields[self::FIELD_VALIDITY_INTERVAL_START])
                ? CborInteger::unsignedFromCbor(
                    $fields[self::FIELD_VALIDITY_INTERVAL_START],
                    $context.' validity interval start'
                )
                : null,
            isset($fields[self::FIELD_AUXILIARY_DATA_HASH])
                ? Shape::bytes($fields[self::FIELD_AUXILIARY_DATA_HASH], $context.' auxiliary data hash', 32)
                : null,
            isset($fields[self::FIELD_SCRIPT_DATA_HASH])
                ? Shape::bytes($fields[self::FIELD_SCRIPT_DATA_HASH], $context.' script data hash', 32)
                : null,
            isset($fields[self::FIELD_MINT])
                ? MultiAsset::fromCbor($fields[self::FIELD_MINT], $context.' mint', signed: true)
                : null,
            $networkId,
            isset($fields[self::FIELD_TOTAL_COLLATERAL])
                ? CborInteger::unsignedFromCbor($fields[self::FIELD_TOTAL_COLLATERAL], $context.' total collateral')
                : null,
            isset($fields[self::FIELD_COLLATERAL_RETURN])
                ? TransactionOutput::fromCbor($fields[self::FIELD_COLLATERAL_RETURN], $context.' collateral return')
                : null,
            $requiredSigners,
            $requiredSignerForm,
        );
    }

    public function toCbor(): CBORObject
    {
        $entries = [];
        foreach ($this->fields as $key => $field) {
            $entries[] = [$this->keys[$key]->toCbor(), $this->rebuild($key, $field)];
        }

        return $this->form->wrap($entries);
    }

    /**
     * The exact bytes the transaction hash is taken over, rebuilt from the model rather than sliced out of the input.
     */
    public function encode(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    public function hash(): string
    {
        return Blake2b::hash256($this->encode());
    }

    public function hashHex(): string
    {
        return bin2hex($this->hash());
    }

    private function rebuild(int $key, CBORObject $field): CBORObject
    {
        return match ($key) {
            self::FIELD_INPUTS, self::FIELD_COLLATERAL, self::FIELD_REFERENCE_INPUTS => $this->inputSetForms[$key]->wrap(
                array_map(static fn (TransactionInput $i): CBORObject => $i->toCbor(), $this->inputSets[$key])
            ),
            self::FIELD_OUTPUTS => $this->outputForm->wrap(
                array_map(static fn (TransactionOutput $o): CBORObject => $o->toCbor(), $this->outputs)
            ),
            self::FIELD_FEE => $this->fee->toCbor(),
            self::FIELD_TTL => $this->ttl->toCbor(),
            self::FIELD_VALIDITY_INTERVAL_START => $this->validityIntervalStart->toCbor(),
            self::FIELD_AUXILIARY_DATA_HASH => ByteStringObject::create($this->auxiliaryDataHash),
            self::FIELD_SCRIPT_DATA_HASH => ByteStringObject::create($this->scriptDataHash),
            self::FIELD_MINT => $this->mint->toCbor(),
            self::FIELD_NETWORK_ID => $this->networkId->toCbor(),
            self::FIELD_TOTAL_COLLATERAL => $this->totalCollateral->toCbor(),
            self::FIELD_COLLATERAL_RETURN => $this->collateralReturn->toCbor(),
            self::FIELD_REQUIRED_SIGNERS => $this->requiredSignerForm->wrap(
                array_map(
                    static fn (string $s): CBORObject => ByteStringObject::create($s),
                    $this->requiredSigners
                )
            ),
            default => $field,
        };
    }

    /**
     * @return list<TransactionInput>
     */
    public function inputs(): array
    {
        return $this->inputSets[self::FIELD_INPUTS];
    }

    /**
     * @return list<TransactionInput>
     */
    public function collateral(): array
    {
        return $this->inputSets[self::FIELD_COLLATERAL] ?? [];
    }

    /**
     * @return list<TransactionInput>
     */
    public function referenceInputs(): array
    {
        return $this->inputSets[self::FIELD_REFERENCE_INPUTS] ?? [];
    }

    /**
     * @return list<TransactionOutput>
     */
    public function outputs(): array
    {
        return $this->outputs;
    }

    public function fee(): CborInteger
    {
        return $this->fee;
    }

    public function ttl(): ?CborInteger
    {
        return $this->ttl;
    }

    public function validityIntervalStart(): ?CborInteger
    {
        return $this->validityIntervalStart;
    }

    public function auxiliaryDataHash(): ?string
    {
        return $this->auxiliaryDataHash;
    }

    public function scriptDataHash(): ?string
    {
        return $this->scriptDataHash;
    }

    public function mint(): ?MultiAsset
    {
        return $this->mint;
    }

    public function networkId(): ?CborInteger
    {
        return $this->networkId;
    }

    public function totalCollateral(): ?CborInteger
    {
        return $this->totalCollateral;
    }

    public function collateralReturn(): ?TransactionOutput
    {
        return $this->collateralReturn;
    }

    /**
     * @return list<string>
     */
    public function requiredSigners(): array
    {
        return $this->requiredSigners;
    }

    /**
     * How one of the three input sets was written. Conway wraps a set in CBOR tag 258 and earlier eras do not, and
     * the difference is in the bytes that were hashed, so a caller checking a fixture's shape can see it here.
     */
    public function inputSetForm(int $field): ?SequenceForm
    {
        return $this->inputSetForms[$field] ?? null;
    }

    public function has(int $field): bool
    {
        return isset($this->fields[$field]);
    }

    /**
     * @return list<int>
     */
    public function fieldKeys(): array
    {
        return array_keys($this->fields);
    }
}
