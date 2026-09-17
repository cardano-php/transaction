<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;

/**
 * How an array was written, kept apart from what it held.
 *
 * Three things vary in a transaction and none of them changes its meaning. An array may be definite or indefinite in
 * length; a definite one states its item count in any of the five widths a CBOR head has; and a set may or may not
 * carry the tag 258 that Conway introduced. All of it is part of the bytes the ledger hashed, so the form is
 * recorded when the array is taken apart and replayed when it is put back together.
 *
 * The recorded head is reused only while it still describes what is being written, which for an array head means
 * only while the item count is the one it was read with. A rebuild that adds or drops an item takes the narrowest
 * head that holds the new count instead.
 */
final class SequenceForm
{
    /**
     * The item count the ledger's own encoder switches framing at.
     *
     * cardano-ledger-binary writes an array of up to this many items with a definite-length head, and everything
     * above it with an indefinite-length head and a break byte. The number is `lengthThreshold` in that encoder, and
     * it is 23 because 23 is the largest count a CBOR head carries inline.
     */
    public const LENGTH_THRESHOLD = 23;

    private function __construct(
        public readonly bool $indefinite,
        public readonly ?int $setTagAdditionalInformation,
        private readonly ?int $headAdditionalInformation = null,
        private readonly ?string $headArgument = null,
        private readonly ?int $headItemCount = null,
    ) {}

    /**
     * The form a freshly built array is written in: definite length, no set tag.
     *
     * Building has no bytes to preserve, so it takes the one form every reader accepts. The set tag is left off
     * because it is optional in Conway and adding it costs two bytes per set for nothing.
     */
    public static function definite(): self
    {
        return new self(false, null);
    }

    /**
     * An array written with no length in its head, which runs until a break byte.
     *
     * Reading is where this comes up. Both framings are on chain, and a container has to be put back the way it
     * arrived or its hash moves, so an indefinite length list is recorded as one rather than normalized away.
     */
    public static function indefinite(): self
    {
        return new self(true, null);
    }

    /**
     * The form the ledger's own encoder writes an array of $itemCount items in.
     *
     * cardano-node and cardano-cli serialize through that encoder, so this is the framing a container has to take for
     * its bytes to be the bytes those tools produce. Both framings are well formed and the ledger accepts either, but
     * they are different bytes, and a script hash is an address.
     */
    public static function forLedgerLength(int $itemCount): self
    {
        return new self($itemCount > self::LENGTH_THRESHOLD, null);
    }

    /**
     * @return array{self, list<CborValue>}
     */
    public static function unwrap(CborValue $value, string $context, bool $allowSetTag = true): array
    {
        $setTagAdditionalInformation = null;

        if ($value->isTag()) {
            if (! $allowSetTag || $value->tagNumber() !== CborValue::TAG_SET) {
                throw new DecodeException(sprintf(
                    '%s: expected an array, got %s.',
                    $context,
                    Shape::describe($value)
                ));
            }

            $setTagAdditionalInformation = $value->additionalInformation;
            $value = $value->taggedValue();
        }

        if (! $value->isSequence()) {
            throw new DecodeException(sprintf(
                '%s: expected an array, got %s.',
                $context,
                Shape::describe($value)
            ));
        }

        $items = $value->items();

        return [
            new self(
                $value->isIndefinite(),
                $setTagAdditionalInformation,
                $value->additionalInformation,
                $value->argument,
                count($items),
            ),
            $items,
        ];
    }

    /**
     * @param  list<CborValue>  $items
     */
    public function wrap(array $items): CborValue
    {
        if ($this->indefinite) {
            $list = CborValue::sequence($items, true);
        } elseif ($this->headItemCount !== null && $this->headItemCount === count($items)) {
            $list = CborValue::sequenceAs((int) $this->headAdditionalInformation, $this->headArgument, $items);
        } else {
            $list = CborValue::sequence($items);
        }

        if ($this->setTagAdditionalInformation === null) {
            return $list;
        }

        return CborValue::taggedAs(
            $this->setTagAdditionalInformation,
            TagPayload::forTagNumber(CborValue::TAG_SET, $this->setTagAdditionalInformation),
            $list
        );
    }
}
