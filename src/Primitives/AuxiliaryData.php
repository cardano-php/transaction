<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\TagPayload;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;
use CBOR\CBORObject;
use CBOR\IndefiniteLengthMapObject;
use CBOR\MapObject;
use CBOR\Tag;
use CBOR\Tag\GenericTag;

/**
 * The metadata block, in the three forms the chain carries.
 *
 * Shelley wrote a bare map of label to value. Allegra added auxiliary scripts and made it a pair. Alonzo wrapped the
 * whole thing in tag 259 and moved to a keyed map so each script language has its own field. All three are still on
 * mainnet and a CIP-20 message or a CIP-25 mint may arrive in any of them.
 *
 * Metadata values are carried through as decoded. A label holds arbitrary CBOR that the ledger does not interpret,
 * so there is nothing here to model and everything to preserve: the body's auxiliary data hash is taken over these
 * bytes, and a value rewritten on the way out would break it.
 */
final class AuxiliaryData
{
    public const FORM_SHELLEY = 'shelley';

    public const FORM_SHELLEY_MA = 'shelley-ma';

    public const FORM_ALONZO = 'alonzo';

    public const ALONZO_TAG = 259;

    private const ALONZO_METADATA = 0;

    private const ALONZO_FIELDS = [0, 1, 2, 3, 4];

    /**
     * @param  list<array{CborInteger, CBORObject}>  $metadata
     * @param  list<CBORObject>|array<int, CBORObject>  $slots
     * @param  array<int, CborInteger>  $slotKeys
     */
    private function __construct(
        public readonly string $form,
        private readonly ?MapForm $metadataForm,
        private readonly array $metadata,
        private readonly SequenceForm|MapForm|null $container,
        private readonly array $slots,
        private readonly array $slotKeys,
        private readonly ?int $tagAdditionalInformation,
    ) {}

    public static function fromCbor(CBORObject $object, string $context = 'auxiliary data'): self
    {
        if ($object instanceof Tag) {
            return self::fromAlonzo($object, $context);
        }

        if ($object instanceof MapObject || $object instanceof IndefiniteLengthMapObject) {
            [$form, $metadata] = self::readMetadata($object, $context);

            return new self(self::FORM_SHELLEY, $form, $metadata, null, [], [], null);
        }

        [$container, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if (count($items) !== 2) {
            throw new DecodeException(sprintf(
                '%s: the array form holds metadata and scripts, got %d item(s).',
                $context,
                count($items)
            ));
        }

        [$metadataForm, $metadata] = self::readMetadata($items[0], $context.' metadata');
        SequenceForm::unwrap($items[1], $context.' auxiliary scripts', allowSetTag: false);

        return new self(self::FORM_SHELLEY_MA, $metadataForm, $metadata, $container, $items, [], null);
    }

    private static function fromAlonzo(Tag $object, string $context): self
    {
        $tagNumber = TagPayload::numberOf($object);
        if ($tagNumber !== self::ALONZO_TAG) {
            throw new DecodeException(sprintf('%s: expected tag %d, got tag %d.', $context, self::ALONZO_TAG, $tagNumber));
        }

        [$container, $slots, $slotKeys] = MapForm::unwrapIntKeyed(
            $object->getValue(),
            $context,
            self::ALONZO_FIELDS
        );

        $metadataForm = null;
        $metadata = [];
        if (isset($slots[self::ALONZO_METADATA])) {
            [$metadataForm, $metadata] = self::readMetadata($slots[self::ALONZO_METADATA], $context.' metadata');
        }

        return new self(
            self::FORM_ALONZO,
            $metadataForm,
            $metadata,
            $container,
            $slots,
            $slotKeys,
            $object->getAdditionalInformation(),
        );
    }

    /**
     * @return array{MapForm, list<array{CborInteger, CBORObject}>}
     */
    private static function readMetadata(CBORObject $object, string $context): array
    {
        [$form, $entries] = MapForm::unwrap($object, $context);

        $metadata = [];
        foreach ($entries as [$label, $value]) {
            $metadata[] = [CborInteger::unsignedFromCbor($label, $context.' label'), $value];
        }

        return [$form, $metadata];
    }

    public function toCbor(): CBORObject
    {
        $metadata = $this->metadataCbor();

        if ($this->form === self::FORM_SHELLEY) {
            return $metadata ?? throw new DecodeException('The Shelley form always carries a metadata map.');
        }

        if ($this->form === self::FORM_SHELLEY_MA) {
            $items = $this->slots;
            $items[0] = $metadata ?? $items[0];

            return $this->container->wrap(array_values($items));
        }

        $entries = [];
        foreach ($this->slots as $key => $slot) {
            $entries[] = [
                $this->slotKeys[$key]->toCbor(),
                $key === self::ALONZO_METADATA ? ($metadata ?? $slot) : $slot,
            ];
        }

        return GenericTag::createFromLoadedData(
            (int) $this->tagAdditionalInformation,
            TagPayload::forTagNumber(self::ALONZO_TAG, (int) $this->tagAdditionalInformation),
            $this->container->wrap($entries)
        );
    }

    /** The bytes the auxiliary data hash is taken over, rebuilt from the model rather than sliced out of the input. */
    public function encode(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    /**
     * Blake2b-256 over those bytes, which is what field 7 of the body has to hold.
     *
     * The two live in different halves of the transaction and the ledger checks that they agree, so a builder that
     * takes the hash from anywhere other than the data it is about to attach has an error it will not find until a
     * node refuses the transaction.
     */
    public function hash(): string
    {
        return Blake2b::hash256($this->encode());
    }

    public function hashHex(): string
    {
        return bin2hex($this->hash());
    }

    private function metadataCbor(): ?CBORObject
    {
        if ($this->metadataForm === null) {
            return null;
        }

        $entries = [];
        foreach ($this->metadata as [$label, $value]) {
            $entries[] = [$label->toCbor(), $value];
        }

        return $this->metadataForm->wrap($entries);
    }

    /**
     * The metadata labels, as decimal strings: a label is a ledger uint64 and 721 or 674 read the same either way.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return array_map(static fn (array $entry): string => $entry[0]->value, $this->metadata);
    }

    public function hasLabel(int|string $label): bool
    {
        return in_array((string) $label, $this->labels(), true);
    }

    public function metadataFor(int|string $label): ?CBORObject
    {
        foreach ($this->metadata as [$key, $value]) {
            if ($key->value === (string) $label) {
                return $value;
            }
        }

        return null;
    }
}
