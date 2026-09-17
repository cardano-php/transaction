<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\TagPayload;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;

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
     * @param  list<array{CborInteger, CborValue}>  $metadata
     * @param  list<CborValue>|array<int, CborValue>  $slots
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

    /**
     * Auxiliary data, refusing anything this package cannot write back as it arrived.
     *
     * The same promise Transaction and TransactionBody make, made here for the same reason. This
     * method is public, it is where a caller who decoded the CBOR themselves arrives, and what it
     * returns answers hash(). Body field 7 carries that hash, so a value that rebuilds differently
     * would put a hash in the body for bytes nobody submitted, and nothing downstream could tell.
     */
    public static function fromCbor(CborValue $object, string $context = 'auxiliary data'): self
    {
        $data = self::read($object, $context);
        $arrived = CborCodec::encode($object);
        $rebuilt = $data->encode();

        if ($rebuilt !== $arrived) {
            throw new DecodeException(sprintf(
                '%s: this auxiliary data is written in a way this package cannot write back, so its hash would '
                .'not be the hash of the bytes it arrived as. It arrived as %d bytes and rebuilds as %d.',
                $context,
                strlen($arrived),
                strlen($rebuilt)
            ));
        }

        return $data;
    }

    private static function read(CborValue $object, string $context): self
    {
        if ($object->isTag()) {
            return self::fromAlonzo($object, $context);
        }

        if ($object->isMap()) {
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

    private static function fromAlonzo(CborValue $object, string $context): self
    {
        $tagNumber = $object->tagNumber();
        if ($tagNumber !== self::ALONZO_TAG) {
            throw new DecodeException(sprintf('%s: expected tag %d, got tag %d.', $context, self::ALONZO_TAG, $tagNumber));
        }

        [$container, $slots, $slotKeys] = MapForm::unwrapIntKeyed(
            $object->taggedValue(),
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
            $object->additionalInformation,
        );
    }

    /**
     * @return array{MapForm, list<array{CborInteger, CborValue}>}
     */
    private static function readMetadata(CborValue $object, string $context): array
    {
        [$form, $entries] = MapForm::unwrap($object, $context);

        $metadata = [];
        foreach ($entries as [$label, $value]) {
            $metadata[] = [CborInteger::unsignedFromCbor($label, $context.' label'), $value];
        }

        return [$form, $metadata];
    }

    public function toCbor(): CborValue
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

        return CborValue::taggedAs(
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

    private function metadataCbor(): ?CborValue
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

    public function metadataFor(int|string $label): ?CborValue
    {
        foreach ($this->metadata as [$key, $value]) {
            if ($key->value === (string) $label) {
                return $value;
            }
        }

        return null;
    }
}
