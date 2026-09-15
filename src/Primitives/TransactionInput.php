<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;
use CBOR\ByteStringObject;
use CBOR\CBORObject;

/**
 * A reference to one output of an earlier transaction: its hash and the index of the output inside it.
 */
final class TransactionInput
{
    private function __construct(
        public readonly string $transactionId,
        public readonly CborInteger $index,
        private readonly SequenceForm $form,
    ) {}

    public static function fromCbor(CBORObject $object, string $context): self
    {
        [$form, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if (count($items) !== 2) {
            throw new DecodeException(sprintf(
                '%s: expected 2 items, got %d.',
                $context,
                count($items)
            ));
        }

        return new self(
            Shape::bytes($items[0], $context.' transaction id', 32),
            CborInteger::unsignedFromCbor($items[1], $context.' index'),
            $form,
        );
    }

    public function toCbor(): CBORObject
    {
        return $this->form->wrap([
            ByteStringObject::create($this->transactionId),
            $this->index->toCbor(),
        ]);
    }

    public function transactionIdHex(): string
    {
        return bin2hex($this->transactionId);
    }
}
