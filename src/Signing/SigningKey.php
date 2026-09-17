<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Signing;

use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Exception\SigningException;
use Closure;
use LogicException;
use SensitiveParameter;

/**
 * One Ed25519 key pair, held in memory for as long as it takes to sign and no longer.
 *
 * **Plain Ed25519, not the extended scheme.** A wallet key is derived down a CIP-1852 path from a root, and the
 * derivation needs the extended form: a 64-byte scalar and chain code that no stock Ed25519 library will sign with.
 * A campaign key here is not derived from anything. It is generated on its own, from fresh entropy, and used by that
 * campaign alone, so there is no path, no parent and no chain code, and the key is an ordinary Ed25519 key that
 * libsodium signs with directly. That is the whole reason ext-sodium is sufficient and no CML binding is needed, and
 * it is a property of how the keys are made rather than of the signing code: derive a campaign key from an account
 * xpub instead and every line below stops being enough.
 *
 * **Nothing here persists a key.** There is no path from an instance of this class to a file, a column or a log
 * line, and at this point in the build there is nothing in the application that would call it: custody arrives
 * later, deliberately, with encryption at rest and a recovery record beside it.
 *
 * **Why the secret is not a property.** A private property is private to other code and not to the language.
 * `var_export` walks an object's real properties whatever the class says, ignoring `__debugInfo`, so a key kept in a
 * property is printed in full by anything that exports one, and a helper that exports a stack frame does not know it
 * is looking at a key. The secret is therefore captured by reference inside two closures instead, one that signs and
 * one that zeroes, and neither `var_export`, `print_r` nor `var_dump` reaches a closure's captured variables.
 * Reflection still does, which is the right place for the line to sit: reading it out takes a deliberate act rather
 * than a debug helper nobody thought about.
 *
 * The zeroing is best effort, and worth stating plainly rather than overselling. PHP copies strings on write and
 * does not track the copies, so a caller who passed a seed in from a string it still holds keeps that string.
 * discard() guarantees that this object stops holding anything, not that the process does.
 */
final class SigningKey
{
    public const SEED_BYTES = 32;

    public const SECRET_KEY_BYTES = 64;

    public const PUBLIC_KEY_BYTES = 32;

    public const SIGNATURE_BYTES = 64;

    private bool $discarded = false;

    private Closure $signer;

    private Closure $eraser;

    private function __construct(#[SensitiveParameter] string $secretKey, private readonly string $publicKey)
    {
        // Both closures capture the same variable by reference, so zeroing it through one of them empties the other.
        // An arrow function would capture by value and leave the signer holding its own live copy of the key.
        $this->signer = static function (string $message) use (&$secretKey): string {
            return sodium_crypto_sign_detached($message, $secretKey);
        };

        $this->eraser = static function () use (&$secretKey): void {
            sodium_memzero($secretKey);
            $secretKey = '';
        };
    }

    /**
     * A fresh key pair from the operating system's entropy.
     *
     * This is what a campaign key is made with and what every test that needs a signature uses. There is no seed to
     * write down and no way to make the same key twice, which is the point: a key that can be regenerated is a key
     * whose regeneration procedure is a second copy of it.
     */
    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        $key = new self(
            sodium_crypto_sign_secretkey($pair),
            sodium_crypto_sign_publickey($pair),
        );

        sodium_memzero($pair);

        return $key;
    }

    /**
     * The key a 32-byte seed expands to.
     *
     * This exists so the RFC 8032 vectors can be run, because a published vector is a seed and the public key and
     * signature it has to produce. It is not how a campaign key is made.
     */
    public static function fromSeed(#[SensitiveParameter] string $seed): self
    {
        if (strlen($seed) !== self::SEED_BYTES) {
            throw new SigningException(sprintf(
                'An Ed25519 seed is %d bytes, got %d.',
                self::SEED_BYTES,
                strlen($seed)
            ));
        }

        $pair = sodium_crypto_sign_seed_keypair($seed);

        $key = new self(
            sodium_crypto_sign_secretkey($pair),
            sodium_crypto_sign_publickey($pair),
        );

        sodium_memzero($pair);

        return $key;
    }

    /** The 32-byte verification key, which is what goes into a witness and what a credential is hashed from. */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function publicKeyHex(): string
    {
        return bin2hex($this->publicKey);
    }

    /** Blake2b-224 over the verification key: the key hash a required signer field and an address carry. */
    public function credential(): Credential
    {
        return Credential::fromVerificationKey($this->publicKey);
    }

    /**
     * A detached Ed25519 signature over exactly these bytes.
     *
     * Nothing is hashed on the way in. Cardano signs the transaction hash, which is already a digest, and a signer
     * that quietly hashed again would produce witnesses that verify against each other and against nothing the
     * ledger computes. TransactionSigner is where the choice of what to sign is made and named.
     */
    public function sign(string $message): string
    {
        if ($this->discarded) {
            throw new SigningException('This key has been discarded and cannot sign.');
        }

        return ($this->signer)($message);
    }

    /**
     * Zero the secret and refuse to sign again.
     *
     * Calling it twice is not an error: a caller that discards in a finally block and an object that is then
     * collected would otherwise have to coordinate.
     */
    public function discard(): void
    {
        if ($this->discarded) {
            return;
        }

        ($this->eraser)();
        $this->discarded = true;
    }

    public function isDiscarded(): bool
    {
        return $this->discarded;
    }

    public function __destruct()
    {
        $this->discard();
    }

    /**
     * Refuse to be turned into bytes something else can keep.
     *
     * serialize() and a cache driver both end up here, and both would write a secret key somewhere this class cannot
     * reach. Throwing is louder than returning a redacted array, because a caller that serialized a key and got a
     * redacted one back would carry on believing it had stored something usable.
     */
    public function __serialize(): array
    {
        throw new LogicException('A signing key cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A signing key cannot be unserialized.');
    }

    public function __sleep(): array
    {
        throw new LogicException('A signing key cannot be serialized.');
    }

    /**
     * Refuse to be copied.
     *
     * A clone would share the captured secret with the original, so discarding either would silently stop the other
     * signing. Two objects for one key is not a shape anything here needs.
     */
    public function __clone()
    {
        throw new LogicException('A signing key cannot be cloned.');
    }

    /**
     * What var_dump and a stack trace see. The secret is not in it, and is not in the properties behind it either.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'publicKey' => $this->publicKeyHex(),
            'secretKey' => $this->discarded ? '(discarded)' : '(withheld)',
        ];
    }
}
