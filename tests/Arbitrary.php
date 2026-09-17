<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

/**
 * A CBOR document of no particular shape, written straight out as bytes so that what it should decode to is already
 * known.
 *
 * Heads are written at a width chosen from the ones that hold the argument, containers are definite or indefinite,
 * strings arrive whole or in chunks, and tags nest. None of that changes what a document says and all of it is in
 * the bytes, which is what makes it the set of differences a decoder is most likely to normalise away.
 *
 * This is used two ways: on its own, as a document handed to the CBOR layer, and inside GeneratedTransaction, as the
 * content of the transaction fields this package carries through rather than models.
 */
final class Arbitrary
{
    private function __construct() {}

    /**
     * One document. $depth is how far in it already is; past a few levels only leaves are written.
     */
    public static function document(int $depth): string
    {
        $leafOnly = $depth >= 4;
        $kind = mt_rand(0, $leafOnly ? 5 : 10);

        return match ($kind) {
            0 => self::head(0, mt_rand(0, 100000)),
            1 => self::head(1, mt_rand(0, 100000)),
            2 => self::definiteString(2),
            3 => self::definiteString(3),
            4 => self::simple(),
            5 => self::chunkedString(mt_rand(0, 1) === 0 ? 2 : 3),
            6 => self::sequence($depth, false),
            7 => self::sequence($depth, true),
            8 => self::map($depth, false),
            9 => self::map($depth, true),
            default => self::head(6, self::tagNumber()).self::document($depth + 1),
        };
    }

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

        $width = $widths[mt_rand(0, count($widths) - 1)];

        return match ($width) {
            -1 => chr($major << 5 | $argument),
            1 => chr($major << 5 | 24).chr($argument),
            2 => chr($major << 5 | 25).pack('n', $argument),
            4 => chr($major << 5 | 26).pack('N', $argument),
            default => chr($major << 5 | 27).pack('J', $argument),
        };
    }

    public static function definiteString(int $major): string
    {
        $length = mt_rand(0, 12);
        $payload = '';

        for ($index = 0; $index < $length; $index++) {
            // Text strings stay inside printable ASCII, which is valid UTF-8 whatever bytes land next to it.
            $payload .= $major === 3 ? chr(mt_rand(0x20, 0x7E)) : chr(mt_rand(0, 255));
        }

        return self::head($major, $length).$payload;
    }

    public static function chunkedString(int $major): string
    {
        $bytes = chr($major << 5 | 31);

        for ($index = 0, $chunks = mt_rand(0, 3); $index < $chunks; $index++) {
            $bytes .= self::definiteString($major);
        }

        return $bytes."\xff";
    }

    public static function sequence(int $depth, bool $indefinite): string
    {
        $count = mt_rand(0, 4);
        $items = '';

        for ($index = 0; $index < $count; $index++) {
            $items .= self::document($depth + 1);
        }

        return $indefinite
            ? chr(4 << 5 | 31).$items."\xff"
            : self::head(4, $count).$items;
    }

    /**
     * A map whose keys are distinct integers, so the document is one a decoder is allowed to accept.
     */
    public static function map(int $depth, bool $indefinite): string
    {
        $count = mt_rand(0, 4);
        $entries = '';

        for ($index = 0; $index < $count; $index++) {
            $entries .= self::head(0, $index).self::document($depth + 1);
        }

        return $indefinite
            ? chr(5 << 5 | 31).$entries."\xff"
            : self::head(5, $count).$entries;
    }

    public static function simple(): string
    {
        return match (mt_rand(0, 6)) {
            0 => "\xf4",
            1 => "\xf5",
            2 => "\xf6",
            3 => "\xf7",
            4 => chr(7 << 5 | mt_rand(0, 19)),
            5 => "\xf9".pack('n', mt_rand(0, 0xFFFF)),
            default => "\xfa".pack('N', mt_rand(0, 0x7FFFFFFF)),
        };
    }

    public static function tagNumber(): int
    {
        return [0, 2, 18, 24, 121, 258, 1004, 100000][mt_rand(0, 7)];
    }

    /**
     * The same document with one byte changed, truncated or added to.
     */
    public static function corrupted(string $bytes): string
    {
        return match (mt_rand(0, 3)) {
            0 => $bytes.chr(mt_rand(0, 255)),
            1 => substr($bytes, 0, max(0, strlen($bytes) - mt_rand(1, 3))),
            2 => self::withByteChanged($bytes),
            default => substr($bytes, mt_rand(1, 2)),
        };
    }

    private static function withByteChanged(string $bytes): string
    {
        if ($bytes === '') {
            return "\x00";
        }

        $position = mt_rand(0, strlen($bytes) - 1);
        $bytes[$position] = chr(mt_rand(0, 255));

        return $bytes;
    }
}
