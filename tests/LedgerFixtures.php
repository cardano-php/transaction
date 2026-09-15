<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Ledger\LedgerParameters;
use Generator;
use RuntimeException;

/**
 * Reads the mainnet corpus the ledger oracle runs over.
 *
 * Line by line rather than all at once. The file is several megabytes of hex and there are ten thousand outputs in
 * it; decoding the lot into memory before the first assertion runs would make a failure slower to reach and tell
 * nobody anything extra.
 */
final class LedgerFixtures
{
    public static function directory(): string
    {
        return __DIR__.'/fixtures/cardano-ledger';
    }

    /**
     * The parameters the corpus was accepted under, read from the committed Koios response rather than typed in.
     *
     * Typing them in would be the same mistake as testing the arithmetic against itself: the numbers would be
     * whatever was believed on the day, and a parameter change would leave the suite green and the platform wrong.
     */
    public static function parameters(): LedgerParameters
    {
        $path = self::directory().'/mainnet-epoch-params.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($rows) || $rows === [] || ! is_array($rows[0])) {
            throw new RuntimeException($path.' does not hold a Koios epoch_params row.');
        }

        return LedgerParameters::fromKoiosEpochParams($rows[0]);
    }

    /**
     * @return array<string, mixed> the raw Koios row, for tests that read a field the parameters do not carry
     */
    public static function epochParamsRow(): array
    {
        $contents = (string) file_get_contents(self::directory().'/mainnet-epoch-params.json');

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR)[0];
    }

    /**
     * Each committed transaction: its hash, the length of its CBOR, the size the chain charged it on, the fee it
     * paid, and its outputs as raw bytes.
     *
     * `bytes` and `size` are not the same number and are not meant to be. See
     * LedgerOracleTest::test_the_charged_size_is_one_byte_less_than_the_serialized_length.
     *
     * @return Generator<int, array{tx: string, bytes: int, size: int, fee: string, outputs: list<string>}>
     */
    public static function transactions(): Generator
    {
        $path = self::directory().'/mainnet-outputs.jsonl';
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        try {
            $number = 0;
            while (($line = fgets($handle)) !== false) {
                $number++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($row) || ! isset($row['tx'], $row['bytes'], $row['size'], $row['fee'], $row['outputs'])) {
                    throw new RuntimeException(sprintf('%s line %d is not a corpus row.', $path, $number));
                }

                $outputs = [];
                foreach ($row['outputs'] as $index => $hex) {
                    $bytes = @hex2bin($hex);

                    if ($bytes === false) {
                        throw new RuntimeException(sprintf(
                            '%s line %d output %d is not hexadecimal.',
                            $path,
                            $number,
                            $index
                        ));
                    }

                    $outputs[] = $bytes;
                }

                yield [
                    'tx' => $row['tx'],
                    'bytes' => $row['bytes'],
                    'size' => $row['size'],
                    'fee' => $row['fee'],
                    'outputs' => $outputs,
                ];
            }
        } finally {
            fclose($handle);
        }
    }
}
