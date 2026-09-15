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
use CBOR\ByteStringObject;
use CBOR\CBORObject;
use CBOR\IndefiniteLengthMapObject;
use CBOR\MapObject;

/**
 * One output, in either of the two forms the chain still carries.
 *
 * The older form is an array: address, value, and on Alonzo transactions an optional datum hash. Babbage added a map
 * keyed by small integers, which is how a datum can be inline and how a script can be carried by reference. Both are
 * alive on mainnet, a transaction may hold one of each, and neither may be rewritten as the other: the form is part
 * of what was hashed.
 *
 * Address bytes are kept as bytes. Parsing them into a network tag and a payment credential is the next step's work,
 * and a decoder that guessed at it here would refuse transactions the ledger took.
 */
final class TransactionOutput
{
    public const FORM_LEGACY = 'legacy';

    public const FORM_MAP = 'map';

    private const MAP_ADDRESS = 0;

    private const MAP_VALUE = 1;

    private const MAP_FIELDS = [0, 1, 2, 3];

    /**
     * @param  list<CBORObject>|array<int, CBORObject>  $slots
     * @param  array<int, CborInteger>  $keys
     */
    private function __construct(
        public readonly string $form,
        public readonly string $address,
        public readonly Value $value,
        private readonly SequenceForm|MapForm $container,
        private readonly array $slots,
        private readonly array $keys,
    ) {}

    /**
     * An output built rather than decoded: an address and a value, and nothing else.
     *
     * The Babbage map form is what gets written. It is two bytes longer than the legacy array for a plain output,
     * and it is the form every node and every indexer in current use reads; the array form is kept in the decoder
     * because mainnet history is full of it, not because anything should still be emitting it.
     */
    public static function create(string $address, Value $value): self
    {
        if ($address === '') {
            throw new DecodeException('An output needs an address.');
        }

        return new self(
            self::FORM_MAP,
            $address,
            $value,
            MapForm::definite(),
            [self::MAP_ADDRESS => ByteStringObject::create($address), self::MAP_VALUE => $value->toCbor()],
            [self::MAP_ADDRESS => CborInteger::of(0), self::MAP_VALUE => CborInteger::of(1)],
        );
    }

    /**
     * The same output holding a different value, in the same form.
     *
     * The minimum-UTxO fixed point needs this: the minimum is a function of the serialized output, the coin is part
     * of the serialized output, so raising the coin to meet the minimum can raise the minimum.
     */
    public function withValue(Value $value): self
    {
        $slots = $this->slots;
        $slots[$this->form === self::FORM_MAP ? self::MAP_VALUE : 1] = $value->toCbor();

        return new self($this->form, $this->address, $value, $this->container, $slots, $this->keys);
    }

    /** The serialized bytes of the whole output, which is what the minimum-UTxO formula is measured over. */
    public function encode(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    public static function fromCbor(CBORObject $object, string $context): self
    {
        if ($object instanceof MapObject || $object instanceof IndefiniteLengthMapObject) {
            return self::fromMap($object, $context);
        }

        return self::fromLegacyArray($object, $context);
    }

    private static function fromMap(CBORObject $object, string $context): self
    {
        [$form, $fields, $keys] = MapForm::unwrapIntKeyed($object, $context, self::MAP_FIELDS);

        foreach ([self::MAP_ADDRESS, self::MAP_VALUE] as $required) {
            if (! isset($fields[$required])) {
                throw new DecodeException(sprintf('%s: field %d is missing.', $context, $required));
            }
        }

        return new self(
            self::FORM_MAP,
            Shape::bytes($fields[self::MAP_ADDRESS], $context.' address'),
            Value::fromCbor($fields[self::MAP_VALUE], $context.' value'),
            $form,
            $fields,
            $keys,
        );
    }

    private static function fromLegacyArray(CBORObject $object, string $context): self
    {
        [$form, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if (count($items) !== 2 && count($items) !== 3) {
            throw new DecodeException(sprintf(
                '%s: an array form output holds 2 or 3 items, got %d.',
                $context,
                count($items)
            ));
        }

        if (count($items) === 3) {
            Shape::bytes($items[2], $context.' datum hash', 32);
        }

        return new self(
            self::FORM_LEGACY,
            Shape::bytes($items[0], $context.' address'),
            Value::fromCbor($items[1], $context.' value'),
            $form,
            $items,
            [],
        );
    }

    public function toCbor(): CBORObject
    {
        if ($this->form === self::FORM_MAP) {
            $entries = [];
            foreach ($this->slots as $key => $slot) {
                $entries[] = [$this->keys[$key]->toCbor(), match ($key) {
                    self::MAP_ADDRESS => ByteStringObject::create($this->address),
                    self::MAP_VALUE => $this->value->toCbor(),
                    default => $slot,
                }];
            }

            return $this->container->wrap($entries);
        }

        $items = $this->slots;
        $items[0] = ByteStringObject::create($this->address);
        $items[1] = $this->value->toCbor();

        return $this->container->wrap(array_values($items));
    }

    public function addressHex(): string
    {
        return bin2hex($this->address);
    }

    public function hasInlineDatum(): bool
    {
        return $this->form === self::FORM_MAP && isset($this->slots[2]);
    }

    public function hasScriptReference(): bool
    {
        return $this->form === self::FORM_MAP && isset($this->slots[3]);
    }

    public function hasDatumHash(): bool
    {
        return $this->form === self::FORM_LEGACY && count($this->slots) === 3;
    }
}
