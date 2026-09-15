<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The boundary this step was built inside: construction and hashing, no keys and no signing.
 *
 * A boundary that is only written down in a plan is one nobody can check. This reads the package source and says what
 * is in it, so that the first time something crosses the line it is a failing test rather than a code review nobody
 * scheduled.
 *
 * Verifying a signature is on the right side of the line and generating or using a key is not. One reads a public key
 * and a signature that already exist; the other creates or holds a secret. The transaction corpus needs the first to
 * prove it decoded a witness correctly, and nothing here needs the second until custody, which is step 5 and lives in
 * the application rather than in this package.
 */
class TransactionScopeTest extends TestCase
{
    /**
     * Calls that would mean a key is being made, held, or used to sign.
     */
    private const OUT_OF_SCOPE = [
        'sodium_crypto_sign_keypair',
        'sodium_crypto_sign_seed_keypair',
        'sodium_crypto_sign_secretkey',
        'sodium_crypto_sign_publickey',
        'sodium_crypto_sign_detached',
        'sodium_crypto_sign_ed25519_sk_to_curve25519',
        'sodium_crypto_kdf_derive_from_key',
        'sodium_crypto_pwhash',
        'random_bytes',
        'openssl_pkey_new',
        'openssl_sign',
        'Crypt::',
    ];

    /**
     * The only cryptographic calls the package is allowed to make.
     */
    private const IN_SCOPE = [
        'sodium_crypto_generichash' => 'src/Hash/Blake2b.php',
        'sodium_crypto_sign_verify_detached' => 'src/Primitives/VkeyWitness.php',
    ];

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::packageRoot().'/src')
        );

        foreach ($directory as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_package_holds_no_key_and_signs_nothing(): void
    {
        $found = [];

        foreach (self::sourceFiles() as $path) {
            $source = (string) file_get_contents($path);

            foreach (self::OUT_OF_SCOPE as $call) {
                if (str_contains($source, $call)) {
                    $found[] = self::relative($path).' calls '.$call;
                }
            }
        }

        $this->assertSame([], $found, 'The transaction package has crossed out of construction and hashing.');
    }

    /**
     * The other half of the same statement. A list of forbidden calls only says something while it is complete, and a
     * new cryptographic call arriving under a name nobody thought of would pass the test above in silence.
     */
    public function test_the_only_cryptography_in_the_package_is_hashing_and_signature_verification(): void
    {
        $calls = [];

        foreach (self::sourceFiles() as $path) {
            $source = (string) file_get_contents($path);
            preg_match_all('/\b(sodium_[a-z0-9_]+|openssl_[a-z0-9_]+|hash_hmac|random_[a-z]+)\b/', $source, $matches);

            foreach ($matches[1] as $call) {
                $calls[$call][] = self::relative($path);
            }
        }

        ksort($calls);

        $this->assertSame(array_keys(self::IN_SCOPE), array_keys($calls));

        foreach (self::IN_SCOPE as $call => $file) {
            $this->assertSame([$file], array_unique($calls[$call]), $call.' has spread beyond '.$file);
        }
    }

    private static function packageRoot(): string
    {
        return dirname(__DIR__);
    }

    private static function relative(string $path): string
    {
        return substr($path, strlen(self::packageRoot()) + 1);
    }
}
