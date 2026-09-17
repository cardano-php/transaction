<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The boundary this package is built inside: construction, hashing and signing, with no key kept anywhere.
 *
 * A boundary that is only written down in a plan is one nobody can check. This reads the package source and says what
 * is in it, so that the first time something crosses the line it is a failing test rather than a code review nobody
 * scheduled.
 *
 * The line moved once, deliberately, and it is worth saying where it was and where it is. Through serialization,
 * addresses and the fee arithmetic, the package made a key for nothing and signed nothing; verifying a signature was
 * allowed, because the corpus needs it to show a witness was decoded correctly, and that reads a public key and a
 * signature that already exist. Assembly cannot be proved without producing a witness, so generating a key and
 * signing with it are now inside the line and confined to one file.
 *
 * What stays outside is custody: anything that would encrypt a key, derive one from a passphrase or a wrapping key,
 * or write one down. That is step 5, it lives in the application rather than in this package, and it arrives with
 * encryption at rest, a migration for the columns and a recovery record. Until then, a key exists for the length of
 * one call, which is what the calls listed below are checked against.
 */
class TransactionScopeTest extends TestCase
{
    /**
     * Calls that would mean a key is being kept rather than used and dropped.
     *
     * Each of these is a step towards custody. Encrypting a key implies somewhere to put the ciphertext; deriving one
     * from a passphrase or a wrapping key implies a wrapping key to hold; converting a signing key to a Curve25519
     * key is the shape of a key being repurposed rather than used. None of it belongs here.
     */
    private const OUT_OF_SCOPE = [
        'sodium_crypto_sign_ed25519_sk_to_curve25519',
        'sodium_crypto_kdf_derive_from_key',
        'sodium_crypto_pwhash',
        'sodium_crypto_secretbox',
        'sodium_crypto_aead',
        'openssl_pkey_new',
        'openssl_sign',
        'openssl_encrypt',
        'Crypt::',
        'file_put_contents',
        'DB::',
    ];

    /**
     * The only cryptographic calls the package is allowed to make, and the one file each may appear in.
     *
     * The file is half the assertion. Signing spread across the package would mean key material spread across it too,
     * and the whole basis for saying a key lives for the length of one call is that there is exactly one place that
     * holds one.
     */
    private const IN_SCOPE = [
        'sodium_crypto_generichash' => 'src/Hash/Blake2b.php',
        'sodium_crypto_sign_detached' => 'src/Signing/SigningKey.php',
        'sodium_crypto_sign_keypair' => 'src/Signing/SigningKey.php',
        'sodium_crypto_sign_publickey' => 'src/Signing/SigningKey.php',
        'sodium_crypto_sign_secretkey' => 'src/Signing/SigningKey.php',
        'sodium_crypto_sign_seed_keypair' => 'src/Signing/SigningKey.php',
        'sodium_crypto_sign_verify_detached' => 'src/Primitives/VkeyWitness.php',
        'sodium_memzero' => 'src/Signing/SigningKey.php',
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

    public function test_the_package_never_takes_custody_of_a_key(): void
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

        $this->assertSame([], $found, 'The transaction package has crossed into custody.');
    }

    /**
     * A key is made in one file and nowhere else, and nothing but that file can read one.
     *
     * The list above says which cryptographic calls exist. This says the class that holds a secret cannot be
     * constructed from outside itself, so there is no second route to a key that skipped the wiping and the refusal
     * to be serialized.
     */
    public function test_only_one_class_can_hold_a_secret(): void
    {
        $source = (string) file_get_contents(self::packageRoot().'/src/Signing/SigningKey.php');

        $this->assertStringContainsString(
            'private function __construct(',
            $source,
            'SigningKey can be constructed from outside itself.'
        );

        foreach (self::sourceFiles() as $path) {
            if (str_ends_with($path, 'Signing/SigningKey.php')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'new SigningKey',
                (string) file_get_contents($path),
                self::relative($path).' builds a signing key of its own.'
            );
        }
    }

    /**
     * The other half of the same statement. A list of forbidden calls only says something while it is complete, and a
     * new cryptographic call arriving under a name nobody thought of would pass the test above in silence.
     */
    public function test_the_only_cryptography_in_the_package_is_hashing_and_ed25519(): void
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
