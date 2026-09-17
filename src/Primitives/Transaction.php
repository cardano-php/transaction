<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;

/**
 * A whole signed transaction: body, witnesses, the Alonzo validity flag where the era has one, and auxiliary data.
 *
 * Shelley through Mary wrote three items. Alonzo inserted the boolean that says whether the scripts passed, making
 * four. Both lengths are still on mainnet and the decoder takes either.
 */
final class Transaction
{
    private function __construct(
        public readonly TransactionBody $body,
        public readonly WitnessSet $witnessSet,
        public readonly ?bool $isValid,
        public readonly ?AuxiliaryData $auxiliaryData,
        private readonly SequenceForm $form,
    ) {}

    /**
     * A whole transaction put together from a body, its witnesses and whatever auxiliary data it carries.
     *
     * Four items are written, which is the Alonzo-and-later shape and the only one a node accepts today. The three
     * item form stays in the decoder because mainnet history is full of it.
     *
     * The body's auxiliary data hash and the auxiliary data itself have to agree, and this method does not check
     * that itself: it hands the assembled CBOR back to the decoder, which already refuses a body that claims a hash
     * with nothing attached and data attached with no hash. Assembling through the decoder is what makes every
     * transaction this package emits one it will also read.
     */
    public static function assemble(
        TransactionBody $body,
        WitnessSet $witnessSet,
        ?AuxiliaryData $auxiliaryData = null,
        bool $isValid = true,
    ): self {
        return self::fromCbor(
            SequenceForm::definite()->wrap([
                $body->toCbor(),
                $witnessSet->toCbor(),
                $isValid ? CborValue::bool(true) : CborValue::bool(false),
                $auxiliaryData?->toCbor() ?? CborValue::null(),
            ]),
            'assembled transaction'
        );
    }

    /**
     * The same transaction carrying a different witness set.
     *
     * Building, measuring and signing happen in that order and cannot happen in any other, because the fee is a
     * field of the body the signatures are taken over. So a transaction is assembled with witnesses of the right
     * size holding zeroes, its length is measured, its fee is settled against that length, and the zeroes are
     * swapped for real signatures here. The body is untouched and therefore so is its hash, which is the whole
     * reason the swap is safe.
     */
    public function withWitnessSet(WitnessSet $witnessSet): self
    {
        return new self($this->body, $witnessSet, $this->isValid, $this->auxiliaryData, $this->form);
    }

    public static function fromCbor(CborValue $object, string $context = 'transaction'): self
    {
        [$form, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if (count($items) !== 3 && count($items) !== 4) {
            throw new DecodeException(sprintf(
                '%s: expected 3 or 4 items, got %d.',
                $context,
                count($items)
            ));
        }

        $isValid = count($items) === 4
            ? Shape::bool($items[2], $context.' script validity flag')
            : null;

        $auxiliarySlot = $items[count($items) - 1];
        $auxiliaryData = Shape::isNull($auxiliarySlot)
            ? null
            : AuxiliaryData::fromCbor($auxiliarySlot, $context.' auxiliary data');

        $body = TransactionBody::fromCbor($items[0], $context.' body');

        if ($auxiliaryData === null && $body->auxiliaryDataHash() !== null) {
            throw new DecodeException(sprintf(
                '%s: the body carries an auxiliary data hash but no auxiliary data is attached.',
                $context
            ));
        }

        if ($auxiliaryData !== null && $body->auxiliaryDataHash() === null) {
            throw new DecodeException(sprintf(
                '%s: auxiliary data is attached but the body carries no hash for it.',
                $context
            ));
        }

        return new self(
            $body,
            WitnessSet::fromCbor($items[1], $context.' witness set'),
            $isValid,
            $auxiliaryData,
            $form,
        );
    }

    public function toCbor(): CborValue
    {
        $items = [$this->body->toCbor(), $this->witnessSet->toCbor()];

        if ($this->isValid !== null) {
            $items[] = $this->isValid ? CborValue::bool(true) : CborValue::bool(false);
        }

        $items[] = $this->auxiliaryData?->toCbor() ?? CborValue::null();

        return $this->form->wrap($items);
    }

    public function encode(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    /**
     * Blake2b-256 over the re-encoded body. This is the transaction hash, and it is the whole of assertion two.
     */
    public function hash(): string
    {
        return $this->body->hash();
    }

    public function hashHex(): string
    {
        return $this->body->hashHex();
    }

    public function auxiliaryDataBytes(): ?string
    {
        return $this->auxiliaryData === null ? null : CborCodec::encode($this->auxiliaryData->toCbor());
    }

    /**
     * How many top level items the transaction was written with: three before Alonzo, four from Alonzo on.
     */
    public function topLevelItemCount(): int
    {
        return $this->isValid === null ? 3 : 4;
    }
}
