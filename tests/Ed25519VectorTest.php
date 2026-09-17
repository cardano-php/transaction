<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Signing\SigningKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SodiumException;

/**
 * The published Ed25519 vectors, run against the signing path this package actually uses.
 *
 * This is the lower of the two layers of correctness in this step. It says nothing about Cardano and is not supposed
 * to: it says that when this code asks for a signature, what comes back is the signature RFC 8032 says should come
 * back. Which bytes a Cardano witness covers is the other layer, and it is pinned by the corpus, where every witness
 * was made by a key nobody here holds.
 *
 * The keys in these vectors are the RFC's own, printed in a public standards document since 2017 so that
 * implementations can be checked against each other. They are the only secret keys anywhere in this repository, they
 * are worthless, and they are read from a file rather than generated so that nothing here can quietly start checking
 * its own arithmetic against itself.
 */
class Ed25519VectorTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function vectors(): array
    {
        $fixture = JsonFixture::read('ed25519/rfc8032-vectors.json');

        $cases = [];
        foreach ($fixture['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * A seed expands to the verification key the RFC prints beside it.
     */
    #[DataProvider('vectors')]
    public function test_a_seed_expands_to_the_published_verification_key(array $vector): void
    {
        $key = SigningKey::fromSeed(self::bytes($vector['secret_key']));

        $this->assertSame(
            $vector['public_key'],
            $key->publicKeyHex(),
            $vector['name'].': the seed expanded to a different verification key.'
        );

        $key->discard();
    }

    /**
     * Signing the published message with the published key produces the published signature, byte for byte.
     *
     * Ed25519 is deterministic, which is what makes this assertion possible at all. A scheme that mixed randomness
     * into the signature could only be checked by verifying, and verifying is the weaker claim: a signature made
     * over the wrong message with a consistently wrong implementation still verifies against itself.
     */
    #[DataProvider('vectors')]
    public function test_signing_reproduces_the_published_signature(array $vector): void
    {
        $key = SigningKey::fromSeed(self::bytes($vector['secret_key']));

        $signature = $key->sign(self::bytes($vector['message']));

        $this->assertSame(
            $vector['signature'],
            bin2hex($signature),
            $vector['name'].': the signature is not the one RFC 8032 publishes.'
        );

        $key->discard();
    }

    /**
     * The message really is the length the RFC says it is.
     *
     * The vectors were transcribed out of a text document that breaks hex across lines. A dropped line would shorten
     * a message and, for every vector but the empty one, the signature check above would catch it. This catches it
     * by the stated length instead, which is the reason the length was kept beside the message when the file was
     * written.
     */
    #[DataProvider('vectors')]
    public function test_the_transcribed_message_is_the_length_the_rfc_states(array $vector): void
    {
        $this->assertSame(
            $vector['message_bytes'],
            strlen(self::bytes($vector['message'])),
            $vector['name'].': the transcribed message is not the length the RFC states.'
        );

        $this->assertSame(32, strlen(self::bytes($vector['secret_key'])), $vector['name'].': seed is not 32 bytes.');
        $this->assertSame(32, strlen(self::bytes($vector['public_key'])), $vector['name'].': key is not 32 bytes.');
        $this->assertSame(64, strlen(self::bytes($vector['signature'])), $vector['name'].': signature not 64 bytes.');
    }

    /**
     * Verification accepts the published signature and refuses every neighbour of it.
     *
     * A verifier that returned true unconditionally would pass the first half. The other three are the ways a
     * witness goes wrong in practice: the message it covers is not the one presented, the signature was altered in
     * flight, or it was made by a different key.
     */
    #[DataProvider('vectors')]
    public function test_verification_accepts_the_vector_and_refuses_its_neighbours(array $vector): void
    {
        $message = self::bytes($vector['message']);
        $signature = self::bytes($vector['signature']);
        $publicKey = self::bytes($vector['public_key']);

        $this->assertTrue(
            sodium_crypto_sign_verify_detached($signature, $message, $publicKey),
            $vector['name'].': the published signature does not verify.'
        );

        $this->assertFalse(
            sodium_crypto_sign_verify_detached($signature, $message.'x', $publicKey),
            $vector['name'].': a signature verified against a message it does not cover.'
        );

        $flipped = $signature;
        $flipped[0] = chr(ord($flipped[0]) ^ 0x01);

        $this->assertFalse(
            sodium_crypto_sign_verify_detached($flipped, $message, $publicKey),
            $vector['name'].': a signature with a flipped bit still verified.'
        );

        $other = SigningKey::generate();

        $this->assertFalse(
            sodium_crypto_sign_verify_detached($signature, $message, $other->publicKey()),
            $vector['name'].': a signature verified under a key that did not make it.'
        );

        $other->discard();
    }

    /**
     * A signature whose length is wrong is refused rather than crashing the caller.
     *
     * libsodium throws on a wrong-length signature instead of returning false, so a witness set carrying a truncated
     * signature would reach a caller as an uncaught SodiumException. VkeyWitness holds the length on the way in and
     * on the way out for exactly this reason, and this is the check that the underlying behaviour it is guarding
     * against is still what it is.
     */
    public function test_a_wrong_length_signature_is_a_refusal_not_a_silent_false(): void
    {
        $vector = self::vectors()['TEST 2'][0];

        $this->expectException(SodiumException::class);

        sodium_crypto_sign_verify_detached(
            substr(self::bytes($vector['signature']), 0, 63),
            self::bytes($vector['message']),
            self::bytes($vector['public_key'])
        );
    }

    /**
     * A seed of the wrong length is refused before libsodium sees it.
     */
    public function test_a_seed_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('An Ed25519 seed is 32 bytes, got 31.');

        SigningKey::fromSeed(str_repeat("\x00", 31));
    }

    private static function bytes(string $hex): string
    {
        return $hex === '' ? '' : (string) hex2bin($hex);
    }
}
