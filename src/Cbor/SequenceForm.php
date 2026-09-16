<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use CBOR\CBORObject;
use CBOR\IndefiniteLengthListObject;
use CBOR\ListObject;
use CBOR\Tag;
use CBOR\Tag\SetTag;

/**
 * How an array was written, kept apart from what it held.
 *
 * Two things vary in a transaction and neither changes its meaning. An array may be definite or indefinite in length,
 * and a set may or may not carry the tag 258 that Conway introduced. Both are part of the bytes the ledger hashed, so
 * the form is recorded when the array is taken apart and replayed when it is put back together.
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
     * @return array{self, list<CBORObject>}
     */
    public static function unwrap(CBORObject $object, string $context, bool $allowSetTag = true): array
    {
        $setTagAdditionalInformation = null;

        if ($object instanceof Tag) {
            if (! $allowSetTag || ! $object instanceof SetTag) {
                throw new DecodeException(sprintf(
                    '%s: expected an array, got %s.',
                    $context,
                    Shape::describe($object)
                ));
            }

            $setTagAdditionalInformation = $object->getAdditionalInformation();
            $object = $object->getValue();
        }

        if (! $object instanceof ListObject && ! $object instanceof IndefiniteLengthListObject) {
            throw new DecodeException(sprintf(
                '%s: expected an array, got %s.',
                $context,
                Shape::describe($object)
            ));
        }

        $items = [];
        foreach ($object as $item) {
            $items[] = $item;
        }

        return [new self($object instanceof IndefiniteLengthListObject, $setTagAdditionalInformation), $items];
    }

    /**
     * @param  list<CBORObject>  $items
     */
    public function wrap(array $items): CBORObject
    {
        if ($this->indefinite) {
            $list = IndefiniteLengthListObject::create();
            foreach ($items as $item) {
                $list->add($item);
            }
        } else {
            $list = ListObject::create($items);
        }

        if ($this->setTagAdditionalInformation === null) {
            return $list;
        }

        return SetTag::createFromLoadedData(
            $this->setTagAdditionalInformation,
            TagPayload::forTagNumber(CBORObject::TAG_SET, $this->setTagAdditionalInformation),
            $list
        );
    }
}
