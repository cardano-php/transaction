<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Exception\AddressException;
use Cardano\Transaction\Hash\Blake2b;

/**
 * One half of an address: a twenty-eight byte hash, and whether it is the hash of a key or of a script.
 *
 * The bytes of the two kinds are indistinguishable. Only the header byte of the address they sit in says which is
 * which, and getting that bit wrong produces an address that is well formed, passes every checksum, and can be
 * satisfied by nobody. So the kind travels with the hash from the moment it is built.
 */
final class Credential
{
    /** A key hash is twenty-eight bytes, written as fifty-six lowercase hex characters. */
    private const HEX = '/^[0-9a-f]{56}$/';

    private function __construct(
        public readonly CredentialKind $kind,
        public readonly string $hash,
    ) {}

    public static function keyHash(string $hex): self
    {
        return new self(CredentialKind::Key, self::raw($hex, 'key hash'));
    }

    public static function scriptHash(string $hex): self
    {
        return new self(CredentialKind::Script, self::raw($hex, 'script hash'));
    }

    /**
     * A key credential from twenty-eight raw bytes, for callers who already hold the digest.
     */
    public static function keyHashBytes(string $bytes): self
    {
        return self::keyHash(self::hexOf($bytes, 'key hash'));
    }

    /**
     * A script credential from twenty-eight raw bytes.
     */
    public static function scriptHashBytes(string $bytes): self
    {
        return self::scriptHash(self::hexOf($bytes, 'script hash'));
    }

    /**
     * The credential of an Ed25519 verification key: blake2b-224 over the thirty-two key bytes.
     *
     * This is a hash, not a derivation. No key is generated here and nothing is signed; the input is a public key a
     * caller already holds, and the output is the twenty-eight bytes an address carries in its place.
     */
    public static function fromVerificationKey(string $publicKey): self
    {
        if (strlen($publicKey) !== 32) {
            throw new AddressException(sprintf(
                'An Ed25519 verification key is 32 bytes, got %d.',
                strlen($publicKey)
            ));
        }

        return new self(CredentialKind::Key, Blake2b::hash224($publicKey));
    }

    public function hex(): string
    {
        return bin2hex($this->hash);
    }

    public function isScript(): bool
    {
        return $this->kind === CredentialKind::Script;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && hash_equals($this->hash, $other->hash);
    }

    /**
     * Hex in, raw bytes out, with nothing forgiven.
     *
     * Uppercase, whitespace and an `0x` prefix are all refused rather than cleaned up. Each of them means the value
     * arrived from somewhere that spells a hash differently from this code, and that is worth finding out about
     * while the address is still being built.
     */
    private static function raw(string $hex, string $what): string
    {
        if (preg_match(self::HEX, $hex) !== 1) {
            throw new AddressException(sprintf(
                'A %s is exactly 56 lowercase hex characters, got: %s',
                $what,
                $hex
            ));
        }

        return (string) hex2bin($hex);
    }

    private static function hexOf(string $bytes, string $what): string
    {
        if (strlen($bytes) !== Blake2b::DIGEST_CREDENTIAL) {
            throw new AddressException(sprintf(
                'A %s is %d bytes, got %d.',
                $what,
                Blake2b::DIGEST_CREDENTIAL,
                strlen($bytes)
            ));
        }

        return bin2hex($bytes);
    }
}
