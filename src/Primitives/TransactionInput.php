<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\ByteStringForm;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;

/**
 * A reference to one output of an earlier transaction: its hash and the index of the output inside it.
 */
final class TransactionInput
{
    private function __construct(
        public readonly string $transactionId,
        public readonly CborInteger $index,
        private readonly SequenceForm $form,
        private readonly ByteStringForm $transactionIdForm,
    ) {}

    /**
     * An input built rather than decoded: the hash of the transaction that made the output, and which output it was.
     *
     * The hash arrives as raw bytes, not hex. Everything in this package that names a transaction names it in bytes,
     * and a 64 character hex string is exactly twice the length a 32 byte hash is, so a caller who passed the wrong
     * one would otherwise get an input pointing at nothing and find out on submission.
     */
    public static function of(string $transactionId, int $index): self
    {
        if (strlen($transactionId) !== 32) {
            throw new DecodeException(sprintf(
                'A transaction id is 32 bytes, got %d. Pass raw bytes, not hex.',
                strlen($transactionId)
            ));
        }

        if ($index < 0) {
            throw new DecodeException(sprintf('An output index cannot be %d.', $index));
        }

        return new self(
            $transactionId,
            CborInteger::of($index),
            SequenceForm::definite(),
            ByteStringForm::shortest(),
        );
    }

    public static function fromCbor(CborValue $object, string $context): self
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
            ByteStringForm::of($items[0]),
        );
    }

    public function toCbor(): CborValue
    {
        return $this->form->wrap([
            $this->transactionIdForm->wrap($this->transactionId),
            $this->index->toCbor(),
        ]);
    }

    public function transactionIdHex(): string
    {
        return bin2hex($this->transactionId);
    }
}
