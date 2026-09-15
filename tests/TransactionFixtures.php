<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use RuntimeException;

/**
 * Reads the committed corpus. The manifest is the index, not the directory listing: a file nobody wrote an entry for
 * is a file nobody said anything about, and FixtureManifestTest is what turns that into a failure.
 */
final class TransactionFixtures
{
    public static function directory(): string
    {
        return __DIR__.'/fixtures/cardano-tx';
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $path = self::directory().'/manifest.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException($path.' is not a JSON object.');
        }

        return $manifest;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function section(string $name): array
    {
        return self::manifest()[$name] ?? [];
    }

    public static function bytes(string $file): string
    {
        $path = self::directory().'/'.$file;
        $hex = file_get_contents($path);

        if ($hex === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        $bytes = hex2bin(trim($hex));

        if ($bytes === false) {
            throw new RuntimeException($path.' is not valid hexadecimal.');
        }

        return $bytes;
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provider(string $section): array
    {
        $cases = [];
        foreach (self::section($section) as $fixture) {
            $cases[$fixture['id']] = [$fixture];
        }

        return $cases;
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function chainFixtures(): array
    {
        return self::provider('chain');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function negativeFixtures(): array
    {
        return self::provider('negative');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function perturbedFixtures(): array
    {
        return self::provider('perturbed');
    }

    /**
     * The fixture ids that carry a shape.
     *
     * @return list<string>
     */
    public static function fixturesWithShape(string $shape): array
    {
        $ids = [];
        foreach (self::section('chain') as $fixture) {
            if (in_array($shape, $fixture['shapes'], true)) {
                $ids[] = $fixture['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public static function chainFixture(string $id): array
    {
        foreach (self::section('chain') as $fixture) {
            if ($fixture['id'] === $id) {
                return $fixture;
            }
        }

        throw new RuntimeException('No chain fixture named '.$id);
    }
}
