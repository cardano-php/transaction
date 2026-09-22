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
 * **Two kinds of key, one signature scheme.** Both kinds produce Ed25519 signatures that any Ed25519 verifier accepts,
 * and both witness a transaction the same way. They differ in what the secret is.
 *
 * A seed key is plain Ed25519 (RFC 8032). The secret is a 32-byte seed, and libsodium hashes it to get the scalar
 * and the nonce prefix. generate() and fromSeed() make one, and it is what a key generated on its own, from fresh
 * entropy, needs: there is no path, no parent and no chain code.
 *
 * An extended key is BIP32-Ed25519, the scheme a wallet key derived down a CIP-1852 or CIP-1855 path uses and the
 * one cardano-cli and cardano-signer write as a `*ExtendedSigningKeyShelley_ed25519_bip32` file. The secret is a
 * scalar kL and a nonce key kR, 32 bytes each, and kL is used as it stands: there is no seed to hash. fromExtended()
 * and fromExtendedTextEnvelope() make one. Its signature is the reference one, byte for byte: the nonce r is
 * SHA-512(kR || M) reduced modulo L, R is r times the base point, and S is r + SHA-512(R || A || M) kL modulo L.
 *
 * Libsodium signs with a seed key directly. For an extended key it has every piece but one: PHP's sodium extension
 * exposes no base point multiplication that leaves a scalar unclamped, and neither kL nor r may be clamped. The
 * ristretto255 functions fill the gap. Their base point multiplication does not clamp, and their scalar arithmetic
 * works modulo the same L, so every operation on kL, kR and r runs inside libsodium. What comes back is R in its
 * ristretto255 encoding, which stands for four Edwards points differing by a point of small order.
 * Edwards25519::candidates() lists the four, and the one kept is the one whose signature verifies, which is the
 * point r times the base point and no other. The verification key kL times the base point comes back the same
 * way and is settled by Edwards25519::primeOrderPoint(). Those two conversions are the only arithmetic done in PHP,
 * and they touch R and the verification key alone, both of which are published anyway.
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

    /** kL or kR, each half of an extended secret. */
    public const EXTENDED_HALF_BYTES = 32;

    /** kL, kR, the verification key and the chain code: the extended key cardano-cli and cardano-signer write. */
    public const EXTENDED_KEY_BYTES = 128;

    /** The two type labels an extended signing key file carries. */
    public const EXTENDED_ENVELOPE_TYPES = [
        'PaymentExtendedSigningKeyShelley_ed25519_bip32',
        'ExtendedSigningKeyShelley_ed25519_bip32',
    ];

    private bool $discarded = false;

    private Closure $signer;

    private Closure $eraser;

    private function __construct(
        #[SensitiveParameter] string $secretKey,
        private readonly string $publicKey,
        private readonly bool $extended = false,
    ) {
        // Both closures capture the same variable by reference, so zeroing it through one of them empties the other.
        // An arrow function would capture by value and leave the signer holding its own live copy of the key.
        if ($extended) {
            $this->signer = static function (string $message) use (&$secretKey, $publicKey): string {
                return self::signExtended($message, $secretKey, $publicKey);
            };
        } else {
            $this->signer = static function (string $message) use (&$secretKey): string {
                return sodium_crypto_sign_detached($message, $secretKey);
            };
        }

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

    /**
     * An extended BIP32-Ed25519 key from its two secret halves.
     *
     * kL is the scalar and is used exactly as given; kR is the key the nonce is derived from. The verification key is
     * always computed from kL rather than taken on trust, because it is what a witness carries and what the
     * credential in an address is hashed from, and a wrong one would sign transactions that no address of this key
     * can spend from. When one is supplied, as the key file always supplies one, it must be the key kL computes, so
     * a file whose halves have been damaged or mismatched is refused here rather than at a node.
     *
     * Computing it costs one scalar multiplication in PHP on a public point, about 190 milliseconds without GMP, once
     * per key. Each signature afterwards costs about 20 milliseconds, most of it one modular exponentiation.
     */
    public static function fromExtended(
        #[SensitiveParameter] string $kL,
        #[SensitiveParameter] string $kR,
        ?string $publicKey = null,
    ): self {
        if (! function_exists('sodium_crypto_scalarmult_ristretto255_base')) {
            throw new SigningException('Signing with an extended key needs PHP built against libsodium 1.0.18 or newer.');
        }

        foreach (['kL' => $kL, 'kR' => $kR] as $name => $half) {
            if (strlen($half) !== self::EXTENDED_HALF_BYTES) {
                throw new SigningException(sprintf(
                    'An extended key half (%s) is %d bytes, got %d.',
                    $name,
                    self::EXTENDED_HALF_BYTES,
                    strlen($half)
                ));
            }
        }

        if ($publicKey !== null && strlen($publicKey) !== self::PUBLIC_KEY_BYTES) {
            throw new SigningException(sprintf(
                'An Ed25519 verification key is %d bytes, got %d.',
                self::PUBLIC_KEY_BYTES,
                strlen($publicKey)
            ));
        }

        // BIP32-Ed25519 clears the three low bits of kL at the root, and child derivation adds multiples of eight,
        // so every kL a wallet derives is a multiple of eight. A value that is not is some other 32 bytes, most
        // often the seed half of a libsodium secret key passed where kL was meant.
        if ((ord($kL[0]) & 0x07) !== 0) {
            throw new SigningException('This is not an extended key: kL must be a multiple of eight.');
        }

        $scalar = sodium_crypto_core_ristretto255_scalar_reduce(str_pad($kL, 64, "\0"));

        if ($scalar === str_repeat("\0", 32)) {
            sodium_memzero($scalar);

            throw new SigningException('This is not an extended key: kL is a multiple of the group order.');
        }

        $derived = Edwards25519::primeOrderPoint(sodium_crypto_scalarmult_ristretto255_base($scalar));
        sodium_memzero($scalar);

        if ($publicKey !== null && ! hash_equals($derived, $publicKey)) {
            throw new SigningException(sprintf(
                'The verification key given, %s, is not the one this extended key computes, %s.',
                bin2hex($publicKey),
                bin2hex($derived)
            ));
        }

        return new self($kL.$kR, $derived, true);
    }

    /**
     * An extended key from the JSON file cardano-cli and cardano-signer write for one.
     *
     * The type is `PaymentExtendedSigningKeyShelley_ed25519_bip32` for a key derived on a payment path and
     * `ExtendedSigningKeyShelley_ed25519_bip32` for one on any other path, a CIP-1855 policy key among them. The
     * cborHex is a CBOR byte string of 128 bytes, `5880` and then kL, kR, the verification key and the chain code.
     * The verification key in the file is checked against the one kL computes. The chain code is not needed to sign
     * and is ignored.
     *
     * No message thrown from here quotes the file, since the file is the secret.
     */
    public static function fromExtendedTextEnvelope(#[SensitiveParameter] string $json): self
    {
        try {
            $envelope = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SigningException('An extended signing key file must be JSON.');
        }

        if (! is_array($envelope) || ! is_string($envelope['type'] ?? null) || ! is_string($envelope['cborHex'] ?? null)) {
            throw new SigningException('An extended signing key file must have a type and a cborHex.');
        }

        if (! in_array($envelope['type'], self::EXTENDED_ENVELOPE_TYPES, true)) {
            throw new SigningException(sprintf(
                'A key file of type %s is not an extended signing key; expected %s.',
                json_encode($envelope['type']),
                implode(' or ', self::EXTENDED_ENVELOPE_TYPES)
            ));
        }

        $cborHex = $envelope['cborHex'];
        unset($envelope);

        if (strlen($cborHex) !== 4 + 2 * self::EXTENDED_KEY_BYTES
            || strtolower(substr($cborHex, 0, 4)) !== '5880'
            || ! ctype_xdigit($cborHex)) {
            throw new SigningException(sprintf(
                'The cborHex of an extended signing key is 5880 followed by %d bytes of hex.',
                self::EXTENDED_KEY_BYTES
            ));
        }

        $bytes = (string) hex2bin(substr($cborHex, 4));
        sodium_memzero($cborHex);

        try {
            return self::fromExtended(substr($bytes, 0, 32), substr($bytes, 32, 32), substr($bytes, 64, 32));
        } finally {
            sodium_memzero($bytes);
        }
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

    /** Whether this is an extended BIP32-Ed25519 key rather than a seed key. */
    public function isExtended(): bool
    {
        return $this->extended;
    }

    /**
     * The extended signature, computed with the reference nonce and returned only once it verifies.
     *
     * Of the four points the ristretto255 encoding of R stands for, exactly one makes the signature verify: a
     * candidate R + T with T of small order would need S B = R + T + k A, and S B is R + k A. So the check that
     * chooses R is also the check that the signature is right, and a signature that nothing verifies is never handed
     * out. The three rejected S values are zeroed with everything else, because any two S values for the same r give
     * kL away.
     */
    private static function signExtended(string $message, #[SensitiveParameter] string $secretKey, string $publicKey): string
    {
        $scalar = sodium_crypto_core_ristretto255_scalar_reduce(str_pad(substr($secretKey, 0, 32), 64, "\0"));
        $nonceHash = hash('sha512', substr($secretKey, 32, 32).$message, true);
        $nonce = sodium_crypto_core_ristretto255_scalar_reduce($nonceHash);
        sodium_memzero($nonceHash);

        try {
            foreach (Edwards25519::candidates(sodium_crypto_scalarmult_ristretto255_base($nonce)) as $commitment) {
                $challenge = sodium_crypto_core_ristretto255_scalar_reduce(
                    hash('sha512', $commitment.$publicKey.$message, true)
                );
                $response = sodium_crypto_core_ristretto255_scalar_add(
                    $nonce,
                    sodium_crypto_core_ristretto255_scalar_mul($challenge, $scalar)
                );
                $signature = $commitment.$response;
                sodium_memzero($response);

                if (sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    return $signature;
                }

                sodium_memzero($signature);
            }
        } finally {
            sodium_memzero($scalar);
            sodium_memzero($nonce);
        }

        throw new SigningException('No signature from this extended key verified; the key or the arithmetic is wrong.');
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
            'scheme' => $this->extended ? 'ed25519-bip32' : 'ed25519',
            'secretKey' => $this->discarded ? '(discarded)' : '(withheld)',
        ];
    }
}
