<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Signing\SigningKey;
use RuntimeException;

/**
 * The two throwaway extended keys under `tests/fixtures/cardano-signer`, and what cardano-signer and cardano-cli said
 * about them.
 *
 * The keys are test fixtures. They were derived from a mnemonic generated for the purpose, the mnemonic is committed
 * beside them, and they have never held funds. Every expected value was produced by cardano-signer or cardano-cli,
 * never by this package.
 */
final class CardanoSignerVectors
{
    public const KEYS = ['payment', 'policy'];

    /**
     * @return array<string, mixed>
     */
    public static function vectors(): array
    {
        return JsonFixture::read('cardano-signer/vectors.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(string $name): array
    {
        return self::vectors()['keys'][$name];
    }

    public static function file(string $name): string
    {
        $path = __DIR__.'/fixtures/cardano-signer/'.$name;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        return $contents;
    }

    public static function key(string $name): SigningKey
    {
        return SigningKey::fromExtendedTextEnvelope(self::file(self::entry($name)['skey_file']));
    }

    /**
     * The verification key the vkey file carries, as raw bytes.
     */
    public static function verificationKey(string $name): string
    {
        $envelope = json_decode(self::file(self::entry($name)['vkey_file']), true, 4, JSON_THROW_ON_ERROR);

        return (string) hex2bin(substr($envelope['cborHex'], 4));
    }
}
