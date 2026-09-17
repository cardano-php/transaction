<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use RuntimeException;

/**
 * A plain walk over CBOR bytes that finds every head and rewrites one of them wider.
 *
 * Nothing here uses the package. The point of these documents is to say what the package should do with an encoding
 * mainnet does not happen to use, and a generator built out of the decoder under test could only ever produce
 * documents that decoder already agrees with.
 *
 * Widening a head is the mutation to make because it is the one that changes nothing about what a document means.
 * The item keeps its major type, its argument and its payload; the only difference is how many bytes state the
 * argument. RFC 8949 section 3 gives every argument five encodings and the ledger hashes whichever one it was
 * handed, so a transaction that arrives in a wider head is a different transaction with a different hash and the
 * same contents.
 */
final class HeadWidths
{
    private function __construct() {}

    /**
     * Every head in a document: where it starts, how long it is, its major type and the argument it carries.
     *
     * @return list<array{offset: int, headLength: int, major: int, additionalInformation: int, argument: int}>
     */
    public static function heads(string $bytes): array
    {
        $heads = [];
        $offset = 0;
        self::walk($bytes, $offset, $heads);

        if ($offset !== strlen($bytes)) {
            throw new RuntimeException('The document has trailing bytes; this walker expects exactly one item.');
        }

        return $heads;
    }

    /**
     * The byte range of each item of the top level array, which for a transaction is body, witnesses, flag, metadata.
     *
     * @return list<array{int, int}> offset and length of each item
     */
    public static function topLevelItems(string $bytes): array
    {
        $offset = 0;
        $initial = ord($bytes[$offset]);
        $offset++;
        $additionalInformation = $initial & 0b00011111;
        $count = $additionalInformation;

        if ($additionalInformation >= 24 && $additionalInformation <= 27) {
            $width = 1 << ($additionalInformation - 24);
            $count = 0;
            for ($index = 0; $index < $width; $index++) {
                $count = ($count << 8) | ord($bytes[$offset + $index]);
            }
            $offset += $width;
        }

        $ranges = [];
        for ($index = 0; $index < $count; $index++) {
            $start = $offset;
            $ignored = [];
            self::walk($bytes, $offset, $ignored);
            $ranges[] = [$start, $offset - $start];
        }

        return $ranges;
    }

    /**
     * The same document with the head at $offset written one width wider, or null when it is already the widest.
     *
     * @param  array{offset: int, headLength: int, major: int, additionalInformation: int, argument: int}  $head
     */
    public static function widened(string $bytes, array $head): ?string
    {
        $argument = $head['argument'];

        $wider = match (true) {
            $head['additionalInformation'] <= 23 => chr($head['major'] << 5 | 24).chr($argument),
            $head['additionalInformation'] === 24 => chr($head['major'] << 5 | 25).pack('n', $argument),
            $head['additionalInformation'] === 25 => chr($head['major'] << 5 | 26).pack('N', $argument),
            $head['additionalInformation'] === 26 => chr($head['major'] << 5 | 27).pack('J', $argument),
            default => null,
        };

        if ($wider === null) {
            return null;
        }

        return substr($bytes, 0, $head['offset']).$wider.substr($bytes, $head['offset'] + $head['headLength']);
    }

    /**
     * @param  list<array{offset: int, headLength: int, major: int, additionalInformation: int, argument: int}>  $heads
     */
    private static function walk(string $bytes, int &$offset, array &$heads): void
    {
        $start = $offset;

        if ($offset >= strlen($bytes)) {
            throw new RuntimeException('Truncated document at offset '.$offset.'.');
        }

        $initial = ord($bytes[$offset]);
        $offset++;

        $major = $initial >> 5;
        $additionalInformation = $initial & 0b00011111;
        $argumentWidth = 0;

        if ($additionalInformation >= 24 && $additionalInformation <= 27) {
            $argumentWidth = 1 << ($additionalInformation - 24);
        } elseif ($additionalInformation >= 28 && $additionalInformation <= 30) {
            throw new RuntimeException('Reserved additional information '.$additionalInformation.'.');
        }

        $argument = $additionalInformation <= 23 ? $additionalInformation : 0;

        for ($index = 0; $index < $argumentWidth; $index++) {
            $argument = ($argument << 8) | ord($bytes[$offset + $index]);
        }

        $offset += $argumentWidth;
        $indefinite = $additionalInformation === 31;

        if (! $indefinite) {
            $heads[] = [
                'offset' => $start,
                'headLength' => 1 + $argumentWidth,
                'major' => $major,
                'additionalInformation' => $additionalInformation,
                'argument' => $argument,
            ];
        }

        switch ($major) {
            case 0:
            case 1:
            case 7:
                return;

            case 2:
            case 3:
                if ($indefinite) {
                    self::walkUntilBreak($bytes, $offset, $heads, 1);

                    return;
                }

                $offset += $argument;

                return;

            case 4:
                if ($indefinite) {
                    self::walkUntilBreak($bytes, $offset, $heads, 1);

                    return;
                }

                for ($index = 0; $index < $argument; $index++) {
                    self::walk($bytes, $offset, $heads);
                }

                return;

            case 5:
                if ($indefinite) {
                    self::walkUntilBreak($bytes, $offset, $heads, 2);

                    return;
                }

                for ($index = 0; $index < $argument; $index++) {
                    self::walk($bytes, $offset, $heads);
                    self::walk($bytes, $offset, $heads);
                }

                return;

            default:
                self::walk($bytes, $offset, $heads);
        }
    }

    /**
     * @param  list<array{offset: int, headLength: int, major: int, additionalInformation: int, argument: int}>  $heads
     */
    private static function walkUntilBreak(string $bytes, int &$offset, array &$heads, int $itemsPerStep): void
    {
        while (true) {
            if ($offset >= strlen($bytes)) {
                throw new RuntimeException('An indefinite length item never closed.');
            }

            if ($bytes[$offset] === "\xff") {
                $offset++;

                return;
            }

            for ($index = 0; $index < $itemsPerStep; $index++) {
                self::walk($bytes, $offset, $heads);
            }
        }
    }
}
