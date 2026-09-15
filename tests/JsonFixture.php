<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use RuntimeException;

/**
 * Reads a committed JSON fixture.
 *
 * Every file these tests read was fetched once, checked against a second implementation before it was written, and
 * committed with its provenance. Nothing here goes to the network: a test that asked a provider would pass or fail
 * on whether the provider was up that morning, and would stop saying anything about the code.
 */
final class JsonFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function read(string $relativePath): array
    {
        $path = __DIR__.'/fixtures/'.$relativePath;
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
