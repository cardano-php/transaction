<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use RuntimeException;

/**
 * The Arachne conformance corpus, as it sits on disk.
 *
 * The corpus is 126 generated native scripts, each recorded with both of its CBOR encodings, both script hashes, the
 * addresses and governance identifiers those hashes occupy, and the answer the ledger's evaluator gives for a sample
 * of witness sets. It was built by a separate implementation in another language, which is the whole of its value:
 * agreement with it is evidence, where agreement with a value this repository computed is not.
 *
 * tests/fixtures/arachne/README.md records where the files came from and at which commit.
 *
 * Two of the six shapes the grammar admits cannot be built by this package at all, so seventeen vectors are listed
 * here as refused rather than checked. The list is exact and is asserted in both directions: a vector that starts
 * building, or one that stops, fails a test rather than quietly changing what the suite covers.
 */
final class ArachneCorpus
{
    /**
     * The vector format this suite knows how to read.
     *
     * A corpus at a version this does not recognise is refused rather than read around, because an unknown version
     * may have moved a field that would then be silently defaulted.
     */
    public const FORMAT_VERSION = 2;

    /** A container holding no sub-scripts at all. */
    public const GAP_EMPTY_CONTAINER = 'empty container';

    /** An atLeast threshold at or below zero. */
    public const GAP_THRESHOLD_AT_OR_BELOW_ZERO = 'threshold at or below zero';

    /** An atLeast threshold above the number of sub-scripts beside it. */
    public const GAP_THRESHOLD_ABOVE_CHILD_COUNT = 'threshold above the child count';

    /**
     * The remark a vector carries when it holds one of the three shapes, and the shape it names.
     *
     * A vector's remarks are the corpus's own record of why it is unusual, written when the vector was generated.
     * Reading the refusal list off them rather than off a judgement made here means the two cannot drift: a refreshed
     * corpus that adds one of these shapes adds it to the list, and one that stops carrying them empties it.
     */
    private const REMARK_GAPS = [
        'empty-all' => self::GAP_EMPTY_CONTAINER,
        'empty-any' => self::GAP_EMPTY_CONTAINER,
        'required-zero' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'required-exceeds-children' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
    ];

    /**
     * The vectors this package cannot build, and which shape stops it.
     *
     * The first two groups are shapes cardano-cli builds and this package refuses. The third is a shape cardano-cli
     * refuses in the same words this package does, so refusing it is the ecosystem's position rather than this
     * package's alone. tests/fixtures/arachne/README.md carries the cardano-cli output that says which is which.
     */
    private const REFUSED = [
        'degenerate/atleast-negative' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'degenerate/empty-all' => self::GAP_EMPTY_CONTAINER,
        'degenerate/empty-any' => self::GAP_EMPTY_CONTAINER,
        'degenerate/empty-atleast-0' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'degenerate/empty-atleast-1' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
        'degenerate/nested-empty-all' => self::GAP_EMPTY_CONTAINER,
        'duplicate-keys/dup-2x-need-3' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
        'threshold-matrix/atleast-0-of-1' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'threshold-matrix/atleast-0-of-2' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'threshold-matrix/atleast-0-of-3' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'threshold-matrix/atleast-0-of-5' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'threshold-matrix/atleast-0-of-7' => self::GAP_THRESHOLD_AT_OR_BELOW_ZERO,
        'threshold-matrix/atleast-2-of-1' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
        'threshold-matrix/atleast-3-of-2' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
        'threshold-matrix/atleast-4-of-3' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
        'threshold-matrix/atleast-6-of-5' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
        'threshold-matrix/atleast-8-of-7' => self::GAP_THRESHOLD_ABOVE_CHILD_COUNT,
    ];

    /**
     * The credential roles a vector records, and the three this package can derive.
     *
     * Governance identifiers are recorded for every vector in both the CIP-129 and the CIP-105 form. This package has
     * no governance credential type, so that quarter of every vector is read by nothing here. It is named rather than
     * dropped so the gap is a fact in the suite instead of an omission nobody wrote down.
     */
    public const ROLES_RECORDED = ['enterprise', 'baseScriptStake', 'reward', 'governance'];

    public const ROLES_CHECKED = ['enterprise', 'baseScriptStake', 'reward'];

    /** @var array<string, mixed>|null */
    private static ?array $index = null;

    public static function directory(): string
    {
        return __DIR__.'/fixtures/arachne';
    }

    /**
     * @return array<string, mixed>
     */
    public static function index(): array
    {
        return self::$index ??= self::read('vectors/index.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function vector(string $id): array
    {
        return self::read('vectors/'.$id.'.json');
    }

    /**
     * Every vector id the index lists, in the order it lists them.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(
            static fn (array $entry): string => (string) $entry['id'],
            self::index()['vectors']
        );
    }

    /**
     * The vectors this package can build, which are the ones the conformance assertions run over.
     *
     * @return list<string>
     */
    public static function buildable(): array
    {
        return array_values(array_diff(self::ids(), array_keys(self::REFUSED)));
    }

    /**
     * @return array<string, string> vector id => the shape that stops it
     */
    public static function refused(): array
    {
        return self::REFUSED;
    }

    /**
     * The same list, read off the corpus's own remarks rather than off the list above.
     *
     * @return array<string, string> vector id => the shape that stops it
     */
    public static function refusedByRemark(): array
    {
        $refused = [];

        foreach (self::ids() as $id) {
            foreach (self::vector($id)['remarks'] as $remark) {
                if (isset(self::REMARK_GAPS[$remark['code']])) {
                    $refused[$id] = self::REMARK_GAPS[$remark['code']];
                }
            }
        }

        return $refused;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function buildableVectors(): iterable
    {
        foreach (self::buildable() as $id) {
            yield $id => [$id];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedVectors(): iterable
    {
        foreach (self::REFUSED as $id => $gap) {
            yield $id => [$id, $gap];
        }
    }

    /**
     * The vectors whose two encodings hash differently, which are the ones a 24-child container puts on the wrong
     * side of a toolchain boundary.
     *
     * @return iterable<string, array{string}>
     */
    public static function encodingSensitiveVectors(): iterable
    {
        foreach (self::buildable() as $id) {
            if (self::vector($id)['encoding']['encodingSensitive'] === true) {
                yield $id => [$id];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $relativePath): array
    {
        $path = self::directory().'/'.$relativePath;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException($path.' is not a JSON object.');
        }

        return $decoded;
    }
}
