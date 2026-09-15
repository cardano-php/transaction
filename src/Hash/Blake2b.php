<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Hash;

/**
 * The two blake2b digests the ledger uses.
 *
 * Two hundred and fifty-six bits for a transaction hash and for the auxiliary data hash, two hundred and twenty-four
 * for a credential. libsodium's generichash is blake2b with no key and no personalisation, which is what the ledger
 * specifies.
 */
final class Blake2b
{
    public const DIGEST_TRANSACTION = 32;

    public const DIGEST_CREDENTIAL = 28;

    private function __construct() {}

    public static function hash256(string $bytes): string
    {
        return sodium_crypto_generichash($bytes, '', self::DIGEST_TRANSACTION);
    }

    public static function hash224(string $bytes): string
    {
        return sodium_crypto_generichash($bytes, '', self::DIGEST_CREDENTIAL);
    }
}
