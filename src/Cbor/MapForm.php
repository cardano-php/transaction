<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use CBOR\CBORObject;
use CBOR\IndefiniteLengthMapObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\UnsignedIntegerObject;

/**
 * How a map was written, kept apart from what it held.
 *
 * Entry order is part of the bytes and is preserved by returning the entries in the order they were read and writing
 * them back in that order. A repeated key is refused by the CBOR layer below this one; an integer key out of the set
 * a structure defines is refused here.
 *
 * @internal This is the package's own reading machinery. A body holds its map form privately and never hands one out,
 * so nothing outside the package can be holding one, and it is not part of what the package promises to keep
 * working.
 */
final class MapForm
{
    private function __construct(
        public readonly bool $indefinite,
    ) {}

    /** The form a freshly built map is written in: definite length. */
    public static function definite(): self
    {
        return new self(false);
    }

    /**
     * @return array{self, list<array{CBORObject, CBORObject}>}
     */
    public static function unwrap(CBORObject $object, string $context): array
    {
        if (! $object instanceof MapObject && ! $object instanceof IndefiniteLengthMapObject) {
            throw new DecodeException(sprintf(
                '%s: expected a map, got %s.',
                $context,
                Shape::describe($object)
            ));
        }

        $entries = [];
        foreach ($object as $item) {
            $entries[] = [$item->getKey(), $item->getValue()];
        }

        return [new self($object instanceof IndefiniteLengthMapObject), $entries];
    }

    /**
     * A map whose keys are unsigned integers, returned in order and also addressable by key.
     *
     * @param  list<int>  $allowed  the integer keys this structure defines
     * @return array{self, array<int, CBORObject>, array<int, CborInteger>}
     */
    public static function unwrapIntKeyed(CBORObject $object, string $context, array $allowed): array
    {
        [$form, $entries] = self::unwrap($object, $context);

        $values = [];
        $keys = [];
        foreach ($entries as [$key, $value]) {
            if (! $key instanceof UnsignedIntegerObject) {
                throw new DecodeException(sprintf(
                    '%s: expected an unsigned integer key, got %s.',
                    $context,
                    Shape::describe($key)
                ));
            }

            $integer = CborInteger::unsignedFromCbor($key, $context.' key');
            $index = $integer->toInt();

            if (! in_array($index, $allowed, true)) {
                throw new DecodeException(sprintf('%s: unknown field %d.', $context, $index));
            }

            $values[$index] = $value;
            $keys[$index] = $integer;
        }

        return [$form, $values, $keys];
    }

    /**
     * @param  list<array{CBORObject, CBORObject}>  $entries
     */
    public function wrap(array $entries): CBORObject
    {
        if ($this->indefinite) {
            $map = IndefiniteLengthMapObject::create();
            foreach ($entries as [$key, $value]) {
                $map->add($key, $value);
            }

            return $map;
        }

        $items = [];
        foreach ($entries as [$key, $value]) {
            $items[] = MapItem::create($key, $value);
        }

        return MapObject::create($items);
    }
}
