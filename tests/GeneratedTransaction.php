<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

/**
 * Transaction-shaped CBOR, written straight out as bytes.
 *
 * A document shaped at random is never a transaction: it is a four item array with a map body one time in many
 * millions, so routing one through the transaction layer only ever exercises the refusal. What the transaction layer
 * has to be held to is the other answer, that a transaction it accepts comes back as the bytes it arrived in, and
 * reaching that needs documents that are transactions to begin with.
 *
 * So the shape is fixed here and the writing is varied: every head is written at a width chosen from the ones that
 * hold its argument, every container is definite or indefinite, every set may or may not carry tag 258, body and
 * witness fields arrive in arbitrary order, and the fields this package does not model carry arbitrary CBOR
 * including chunked strings and nested tags. All of that is in the bytes the ledger hashed and none of it changes
 * what the transaction says, which is exactly the set of differences a decoder is most likely to normalise away.
 *
 * The bytes are built rather than round-tripped from a model, so what a correct decoder must give back is known
 * before the decoder is asked.
 */
final class GeneratedTransaction
{
    /** The body fields that may or may not be there, which is every one the ledger defines but the three required. */
    private const OPTIONAL_BODY_FIELDS = [3, 4, 5, 6, 8, 9, 11, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22];

    private function __construct() {}

    /**
     * One transaction, as the bytes a node would have handed over.
     */
    public static function bytes(): string
    {
        $auxiliary = mt_rand(0, 2) === 0 ? null : self::auxiliaryData();

        $items = [self::body($auxiliary !== null), self::witnessSet()];

        // Shelley through Mary wrote three items and Alonzo added the script validity flag, making four. Both
        // lengths are on mainnet.
        if (mt_rand(0, 4) !== 0) {
            $items[] = mt_rand(0, 1) === 0 ? "\xf5" : "\xf4";
        }

        $items[] = $auxiliary ?? "\xf6";

        return self::sequence($items);
    }

    /**
     * The same transaction with one thing about it wrong, which the decoder has to refuse rather than read.
     */
    public static function spoiled(): string
    {
        return match (mt_rand(0, 6)) {
            // A body with no inputs field at all.
            0 => self::sequence([
                self::map([
                    [self::head(0, 1), self::sequence([self::output()])],
                    [self::head(0, 2), self::head(0, 17)],
                ]),
                self::witnessSet(),
                "\xf5",
                "\xf6",
            ]),
            // A body field number the ledger never assigned.
            1 => self::sequence([
                self::map([
                    [self::head(0, 0), self::inputSet()],
                    [self::head(0, 1), self::sequence([self::output()])],
                    [self::head(0, 2), self::head(0, 17)],
                    [self::head(0, 10), self::head(0, 1)],
                ]),
                self::witnessSet(),
                "\xf5",
                "\xf6",
            ]),
            // An input whose transaction id is not thirty-two bytes.
            2 => self::sequence([
                self::bodyWith([0 => self::sequence([
                    self::sequence([self::string(2, self::randomBytes(31)), self::head(0, 0)]),
                ])]),
                self::witnessSet(),
                "\xf5",
                "\xf6",
            ]),
            // A transaction of five items.
            3 => self::sequence([self::body(false), self::witnessSet(), "\xf5", "\xf6", "\xf6"]),
            // Auxiliary data attached with no hash for it in the body.
            4 => self::sequence([self::body(false), self::witnessSet(), "\xf5", self::auxiliaryData()]),
            // A hash in the body with no auxiliary data attached.
            5 => self::sequence([self::body(true), self::witnessSet(), "\xf5", "\xf6"]),
            // A fee that is not an integer.
            default => self::sequence([
                self::bodyWith([2 => self::string(2, self::randomBytes(4))]),
                self::witnessSet(),
                "\xf5",
                "\xf6",
            ]),
        };
    }

    // ------------------------------------------------------------------ the transaction

    private static function body(bool $withAuxiliaryHash): string
    {
        return self::bodyWith($withAuxiliaryHash ? [7 => self::string(2, self::randomBytes(32))] : []);
    }

    /**
     * A body carrying the three required fields, a random selection of the optional ones, and $overrides on top.
     *
     * An override replaces the field of the same number where the body already carries it, keeping the position it
     * was in, and is appended where the body does not.
     *
     * @param  array<int, string>  $overrides  field number to the bytes written for it
     */
    private static function bodyWith(array $overrides): string
    {
        $fields = [
            0 => self::inputSet(),
            1 => self::sequence(self::repeat(1, 3, self::output(...))),
            2 => self::head(0, mt_rand(0, 1000000)),
        ];

        foreach (self::OPTIONAL_BODY_FIELDS as $key) {
            if (mt_rand(0, 3) !== 0) {
                continue;
            }

            $fields[$key] = self::bodyField($key);
        }

        foreach ($overrides as $key => $value) {
            $fields[$key] = $value;
        }

        $entries = [];
        foreach ($fields as $key => $value) {
            $entries[] = [self::head(0, $key), $value];
        }

        // The chain writes body fields in ascending order and the ledger does not require it, so both are written
        // here: the order is part of the hashed bytes either way.
        if (mt_rand(0, 3) === 0) {
            shuffle($entries);
        }

        return self::map($entries);
    }

    private static function bodyField(int $key): string
    {
        return match ($key) {
            // ttl, validity interval start, total collateral: unsigned integers.
            3, 8, 17 => self::head(0, mt_rand(0, 1000000000)),
            // Collateral and reference inputs.
            13, 18 => self::inputSet(),
            // Mint: the multiasset map, with quantities that may be negative.
            9 => self::multiAsset(true),
            // The auxiliary data hash is written only when auxiliary data is attached; script data hash is free.
            11 => self::string(2, self::randomBytes(32)),
            // Required signers: key hashes, which Conway may write as a tagged set.
            14 => self::maybeSetTagged(self::sequence(self::repeat(1, 2, static fn (): string => self::string(2, self::randomBytes(28))))),
            15 => self::head(0, mt_rand(0, 1)),
            16 => self::output(),
            // Certificates, withdrawals, the Shelley update field and the Conway governance fields are carried
            // through as they arrived, so anything well formed belongs here.
            default => Arbitrary::document(0),
        };
    }

    private static function witnessSet(): string
    {
        $fields = [];

        if (mt_rand(0, 4) !== 0) {
            $fields[0] = self::maybeSetTagged(self::sequence(self::repeat(1, 3, static fn (): string => self::sequence([
                self::string(2, self::randomBytes(32)),
                self::string(2, self::randomBytes(64)),
            ]))));
        }

        foreach ([1, 2, 3, 4, 5, 6, 7] as $key) {
            if (mt_rand(0, 4) !== 0) {
                continue;
            }

            $fields[$key] = $key === 1
                ? self::maybeSetTagged(self::sequence(self::repeat(1, 2, static fn (): string => Arbitrary::document(1))))
                : Arbitrary::document(1);
        }

        $entries = [];
        foreach ($fields as $key => $value) {
            $entries[] = [self::head(0, $key), $value];
        }

        if (mt_rand(0, 3) === 0) {
            shuffle($entries);
        }

        return self::map($entries);
    }

    private static function auxiliaryData(): string
    {
        return match (mt_rand(0, 2)) {
            0 => self::metadataMap(),
            1 => self::sequence([self::metadataMap(), self::sequence(self::repeat(0, 2, static fn (): string => Arbitrary::document(2)))]),
            default => self::alonzoAuxiliaryData(),
        };
    }

    private static function alonzoAuxiliaryData(): string
    {
        $fields = [];

        if (mt_rand(0, 3) !== 0) {
            $fields[0] = self::metadataMap();
        }

        foreach ([1, 2, 3, 4] as $key) {
            if (mt_rand(0, 2) !== 0) {
                continue;
            }

            $fields[$key] = Arbitrary::document(2);
        }

        $entries = [];
        foreach ($fields as $key => $value) {
            $entries[] = [self::head(0, $key), $value];
        }

        if (mt_rand(0, 3) === 0) {
            shuffle($entries);
        }

        return self::head(6, 259).self::map($entries);
    }

    private static function metadataMap(): string
    {
        $labels = self::distinctIntegers(mt_rand(1, 3), 1000);

        $entries = [];
        foreach ($labels as $label) {
            $entries[] = [self::head(0, $label), Arbitrary::document(2)];
        }

        return self::map($entries);
    }

    // ------------------------------------------------------------------ the pieces

    private static function inputSet(): string
    {
        return self::maybeSetTagged(self::sequence(self::repeat(1, 3, self::input(...))));
    }

    private static function input(): string
    {
        return self::sequence([self::string(2, self::randomBytes(32)), self::head(0, mt_rand(0, 300))]);
    }

    private static function output(): string
    {
        $address = self::string(2, self::randomBytes(mt_rand(0, 1) === 0 ? 29 : 57));
        $value = self::value();

        // Babbage added the map form beside the array form and both are still on chain, so both are written.
        if (mt_rand(0, 1) === 0) {
            $items = [$address, $value];

            if (mt_rand(0, 3) === 0) {
                $items[] = self::string(2, self::randomBytes(32));
            }

            return self::sequence($items);
        }

        $fields = [0 => $address, 1 => $value];

        foreach ([2, 3] as $key) {
            if (mt_rand(0, 2) !== 0) {
                continue;
            }

            $fields[$key] = Arbitrary::document(2);
        }

        $entries = [];
        foreach ($fields as $key => $field) {
            $entries[] = [self::head(0, $key), $field];
        }

        if (mt_rand(0, 3) === 0) {
            shuffle($entries);
        }

        return self::map($entries);
    }

    private static function value(): string
    {
        $coin = self::head(0, mt_rand(1000000, 100000000));

        if (mt_rand(0, 2) === 0) {
            return $coin;
        }

        return self::sequence([$coin, self::multiAsset(false)]);
    }

    private static function multiAsset(bool $signed): string
    {
        $policies = self::distinctByteStrings(mt_rand($signed ? 1 : 0, 2), 28);

        $entries = [];
        foreach ($policies as $policy) {
            $names = self::distinctByteStrings(mt_rand(1, 3), null);

            $assets = [];
            foreach ($names as $name) {
                $quantity = $signed && mt_rand(0, 2) === 0
                    ? self::head(1, mt_rand(0, 1000000))
                    : self::head(0, mt_rand(1, 1000000));
                $assets[] = [self::string(2, $name), $quantity];
            }

            $entries[] = [self::string(2, $policy), self::map($assets)];
        }

        return self::map($entries);
    }

    // ------------------------------------------------------------------ the writing

    /**
     * A head for $major carrying $argument, at a width chosen from the ones that hold it.
     *
     * The shortest is what every encoder in the ecosystem writes and the widest is what nothing writes, which is
     * exactly why both belong here.
     */
    public static function head(int $major, int $argument): string
    {
        $widths = [];

        if ($argument <= 23) {
            $widths[] = -1;
        }

        if ($argument <= 0xFF) {
            $widths[] = 1;
        }

        if ($argument <= 0xFFFF) {
            $widths[] = 2;
        }

        $widths[] = 4;
        $widths[] = 8;

        return match ($widths[mt_rand(0, count($widths) - 1)]) {
            -1 => chr($major << 5 | $argument),
            1 => chr($major << 5 | 24).chr($argument),
            2 => chr($major << 5 | 25).pack('n', $argument),
            4 => chr($major << 5 | 26).pack('N', $argument),
            default => chr($major << 5 | 27).pack('J', $argument),
        };
    }

    /** A definite length string of $major, at a head width chosen from the ones that state its length. */
    public static function string(int $major, string $payload): string
    {
        return self::head($major, strlen($payload)).$payload;
    }

    /**
     * @param  list<string>  $items
     */
    public static function sequence(array $items): string
    {
        if (mt_rand(0, 3) === 0) {
            return chr(4 << 5 | 31).implode('', $items)."\xff";
        }

        return self::head(4, count($items)).implode('', $items);
    }

    /**
     * @param  list<array{string, string}>  $entries
     */
    public static function map(array $entries): string
    {
        $bytes = '';
        foreach ($entries as [$key, $value]) {
            $bytes .= $key.$value;
        }

        if (mt_rand(0, 3) === 0) {
            return chr(5 << 5 | 31).$bytes."\xff";
        }

        return self::head(5, count($entries)).$bytes;
    }

    /** Conway writes a set with tag 258 and earlier eras do not, and the difference is in the hashed bytes. */
    private static function maybeSetTagged(string $bytes): string
    {
        return mt_rand(0, 2) === 0 ? self::head(6, 258).$bytes : $bytes;
    }

    // ------------------------------------------------------------------ small change

    /**
     * @param  callable(): string  $item
     * @return list<string>
     */
    private static function repeat(int $minimum, int $maximum, callable $item): array
    {
        $items = [];

        for ($index = 0, $count = mt_rand($minimum, $maximum); $index < $count; $index++) {
            $items[] = $item();
        }

        return $items;
    }

    public static function randomBytes(int $length): string
    {
        $bytes = '';

        for ($index = 0; $index < $length; $index++) {
            $bytes .= chr(mt_rand(0, 255));
        }

        return $bytes;
    }

    /**
     * @return list<int>
     */
    private static function distinctIntegers(int $count, int $bound): array
    {
        $seen = [];

        while (count($seen) < $count) {
            $seen[mt_rand(0, $bound)] = true;
        }

        return array_map(intval(...), array_keys($seen));
    }

    /**
     * Distinct byte strings, because a map that writes one key twice is not a map and is refused as one.
     *
     * @return list<string>
     */
    private static function distinctByteStrings(int $count, ?int $length): array
    {
        $seen = [];

        while (count($seen) < $count) {
            $seen[self::randomBytes($length ?? mt_rand(0, 32))] = true;
        }

        return array_map(strval(...), array_keys($seen));
    }
}
