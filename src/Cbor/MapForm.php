<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;

/**
 * How a map was written, kept apart from what it held.
 *
 * Entry order is part of the bytes and is preserved by returning the entries in the order they were read and writing
 * them back in that order. A repeated key is refused by the CBOR layer below this one; an integer key out of the set
 * a structure defines is refused here.
 *
 * The head is kept too. All a map head states is how many entries follow it, and CBOR states that five ways, so the
 * head a map arrived in can be written back exactly whenever the entry count has not changed. A rebuild that adds or
 * drops an entry takes the narrowest head that holds the new count, because the old one no longer describes it.
 *
 * @internal This is the package's own reading machinery. A body holds its map form privately and never hands one out,
 * so nothing outside the package can be holding one, and it is not part of what the package promises to keep
 * working.
 */
final class MapForm
{
    private function __construct(
        public readonly bool $indefinite,
        private readonly ?int $headAdditionalInformation = null,
        private readonly ?string $headArgument = null,
        private readonly ?int $headEntryCount = null,
    ) {}

    /** The form a freshly built map is written in: definite length, at the narrowest head that holds its count. */
    public static function definite(): self
    {
        return new self(false);
    }

    /**
     * @return array{self, list<array{CborValue, CborValue}>}
     */
    public static function unwrap(CborValue $value, string $context): array
    {
        if (! $value->isMap()) {
            throw new DecodeException(sprintf(
                '%s: expected a map, got %s.',
                $context,
                Shape::describe($value)
            ));
        }

        $entries = $value->entries();

        return [
            new self($value->isIndefinite(), $value->additionalInformation, $value->argument, count($entries)),
            $entries,
        ];
    }

    /**
     * A map whose keys are unsigned integers, returned in order and also addressable by key.
     *
     * @param  list<int>  $allowed  the integer keys this structure defines
     * @return array{self, array<int, CborValue>, array<int, CborInteger>}
     */
    public static function unwrapIntKeyed(CborValue $value, string $context, array $allowed): array
    {
        [$form, $entries] = self::unwrap($value, $context);

        $values = [];
        $keys = [];
        foreach ($entries as [$key, $entry]) {
            if (! $key->isUnsigned()) {
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

            $values[$index] = $entry;
            $keys[$index] = $integer;
        }

        return [$form, $values, $keys];
    }

    /**
     * @param  list<array{CborValue, CborValue}>  $entries
     */
    public function wrap(array $entries): CborValue
    {
        if ($this->indefinite) {
            return CborValue::map($entries, true);
        }

        if ($this->headEntryCount !== null && $this->headEntryCount === count($entries)) {
            return CborValue::mapAs((int) $this->headAdditionalInformation, $this->headArgument, $entries);
        }

        return CborValue::map($entries);
    }
}
