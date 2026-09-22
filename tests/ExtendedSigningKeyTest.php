<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Ledger\WitnessPlan;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\WitnessSet;
use Cardano\Transaction\Signing\SigningKey;
use Cardano\Transaction\Signing\TransactionSigner;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extended BIP32-Ed25519 keys, checked against the tools that write them.
 *
 * Every expected signature here was made by someone else. The ones over the throwaway keys were made by
 * cardano-signer and cardano-cli, and the ones over libsodium-expanded seeds and the RFC 8032 seeds were made by
 * libsodium's own Ed25519 signer or printed in the RFC. An extended signature is deterministic, so each comparison is
 * byte for byte: a signature that verified but differed would mean a different nonce, and the nonce is the part of a
 * Schnorr signature that is easiest to get subtly wrong.
 *
 * Why a libsodium seed key is a fair reference for an extended one: libsodium expands a seed by hashing it with
 * SHA-512, clamping the first half into the scalar and keeping the second half as the nonce prefix, then signs with
 * r = SHA-512(prefix || M). That is the extended scheme with kL the clamped half and kR the other, so the same halves
 * handed to fromExtended() have to produce libsodium's signature exactly.
 */
class ExtendedSigningKeyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function signerVectors(): array
    {
        $cases = [];

        foreach (CardanoSignerVectors::KEYS as $name) {
            foreach (CardanoSignerVectors::entry($name)['signatures'] as $i => $vector) {
                $cases[$name.' message '.$i] = [$name, $vector['message'], $vector['signature']];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keys(): array
    {
        return ['payment 1852H/1815H/0H/0/0' => ['payment'], 'policy 1855H/1815H/0H' => ['policy']];
    }

    /**
     * Sixteen fixed seeds, each expanded the way libsodium expands one.
     *
     * @return array<string, array{string}>
     */
    public static function seeds(): array
    {
        $cases = [];

        for ($i = 0; $i < 16; $i++) {
            $cases['seed '.$i] = [hash('sha256', 'cardano-php/transaction extended key seed '.$i)];
        }

        return $cases;
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rfc8032Vectors(): array
    {
        return Ed25519VectorTest::vectors();
    }

    /**
     * Each signature cardano-signer made over each message is the one this package makes, byte for byte.
     *
     * cardano-signer also verified each of its signatures when the fixture was written, and the fixture records it.
     * Byte identity means those verifications are verifications of what this package produces.
     */
    #[DataProvider('signerVectors')]
    public function test_the_signature_is_the_one_cardano_signer_makes(string $name, string $message, string $expected): void
    {
        $key = CardanoSignerVectors::key($name);

        $signature = $key->sign((string) hex2bin($message));

        $this->assertSame($expected, bin2hex($signature), $name.': not the signature cardano-signer made.');
        $this->assertTrue(
            sodium_crypto_sign_verify_detached($signature, (string) hex2bin($message), CardanoSignerVectors::verificationKey($name)),
            $name.': the signature does not verify against the vkey file.'
        );

        $key->discard();
    }

    /**
     * Every recorded cardano-signer verification said true. A fixture written with a false in it is a fixture whose
     * reference signature nobody vouched for.
     */
    #[DataProvider('keys')]
    public function test_cardano_signer_verified_every_recorded_signature(string $name): void
    {
        $signatures = CardanoSignerVectors::entry($name)['signatures'];

        $this->assertGreaterThanOrEqual(5, count($signatures));

        foreach ($signatures as $vector) {
            $this->assertTrue($vector['cardano_signer_verify'], $name.': cardano-signer did not verify '.$vector['signature']);
        }
    }

    /**
     * The verification key computed from kL is the one in the vkey file, the one cardano-cli derives and the one
     * embedded in the skey. Its blake2b-224 is the key hash cardano-cli prints, which is also the hash cardano-signer
     * accepted in the enterprise address it was given.
     */
    #[DataProvider('keys')]
    public function test_the_key_and_its_hash_are_the_ones_cardano_cli_derives(string $name): void
    {
        $entry = CardanoSignerVectors::entry($name);
        $key = CardanoSignerVectors::key($name);

        $this->assertTrue($key->isExtended());
        $this->assertSame($entry['public_key'], $key->publicKeyHex());
        $this->assertSame(bin2hex(CardanoSignerVectors::verificationKey($name)), $key->publicKeyHex());
        $this->assertSame($entry['key_hash'], bin2hex(Blake2b::hash224($key->publicKey())));
        $this->assertSame($entry['key_hash'], $key->credential()->hex());
        $this->assertFalse($key->credential()->isScript());

        $key->discard();
    }

    /**
     * Without the embedded verification key, the same key is computed from kL alone.
     */
    #[DataProvider('keys')]
    public function test_the_verification_key_is_computed_when_it_is_not_given(string $name): void
    {
        [$kL, $kR] = self::halves($name);

        $key = SigningKey::fromExtended($kL, $kR);

        $this->assertSame(CardanoSignerVectors::entry($name)['public_key'], $key->publicKeyHex());

        $key->discard();
    }

    /**
     * The witness TransactionSigner makes over a mainnet body is the one cardano-cli makes over that body.
     */
    #[DataProvider('keys')]
    public function test_the_transaction_witness_is_the_one_cardano_cli_makes(string $name): void
    {
        $vectors = CardanoSignerVectors::vectors();
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes('chain/plain-ada-payment.hex'));
        $key = CardanoSignerVectors::key($name);

        $this->assertSame($vectors['transaction']['body_hash'], bin2hex($transaction->body->hash()));

        $witness = TransactionSigner::witness($transaction->body, $key);

        // cardano-cli writes a key witness as [0, [vkey, signature]]; the inner pair is the witness itself.
        $this->assertSame($vectors['keys'][$name]['transaction_witness'], '8200'.bin2hex($witness->encode()));
        $this->assertTrue($witness->verifies($transaction->body->hash()));

        $key->discard();
    }

    /**
     * Signing a whole transaction with an extended key swaps the measuring witness for one that verifies against the
     * body, and leaves the transaction the size its fee was measured at.
     */
    public function test_a_transaction_signed_with_an_extended_key_verifies(): void
    {
        $chain = TransactionDecoder::decode(TransactionFixtures::bytes('chain/plain-ada-payment.hex'));
        $unsigned = Transaction::assemble($chain->body, WitnessSet::of(WitnessPlan::forSignatures(1)->dummyWitnesses()));
        $key = CardanoSignerVectors::key('payment');

        $signed = TransactionSigner::sign($unsigned, $key);

        $this->assertCount(1, $signed->witnessSet->vkeyWitnesses());
        $this->assertSame($key->publicKeyHex(), $signed->witnessSet->vkeyWitnesses()[0]->vkeyHex());
        $this->assertTrue(TransactionSigner::witnessesVerify($signed));
        $this->assertSame($chain->body->hash(), $signed->body->hash());
        $this->assertSame(strlen($unsigned->encode()), strlen($signed->encode()));

        $key->discard();
    }

    /**
     * On a libsodium-expanded seed, an extended key signs exactly as libsodium does, and has the same public key.
     *
     * This is the check that the R chosen from the four candidates is always r times the base point: any other
     * candidate changes the first half of the signature and the challenge with it.
     */
    #[DataProvider('seeds')]
    public function test_an_expanded_seed_signs_exactly_as_libsodium_does(string $seedHex): void
    {
        $seed = (string) hex2bin($seedHex);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        [$kL, $kR] = self::expand($seed);

        $key = SigningKey::fromExtended($kL, $kR, sodium_crypto_sign_publickey($pair));

        foreach (['', 'a', str_repeat("\x00", 32), hash('sha256', $seedHex, true), str_repeat('m', 1000)] as $message) {
            $this->assertSame(
                bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair))),
                bin2hex($key->sign($message)),
                'An extended key built from a libsodium expansion did not sign as libsodium does.'
            );
        }

        $key->discard();
    }

    /**
     * The RFC 8032 seeds, expanded and handed over as extended halves, reproduce the published signatures.
     */
    #[DataProvider('rfc8032Vectors')]
    public function test_the_rfc_8032_seeds_sign_as_published_through_the_extended_path(array $vector): void
    {
        [$kL, $kR] = self::expand((string) hex2bin($vector['secret_key']));

        $key = SigningKey::fromExtended($kL, $kR);

        $this->assertSame($vector['public_key'], $key->publicKeyHex());
        $this->assertSame(
            $vector['signature'],
            bin2hex($key->sign($vector['message'] === '' ? '' : (string) hex2bin($vector['message']))),
            $vector['name'].': not the signature RFC 8032 publishes.'
        );

        $key->discard();
    }

    /**
     * A signature refuses every neighbour: another message, a flipped bit in either half, another key.
     */
    #[DataProvider('keys')]
    public function test_tampering_and_the_wrong_key_are_refused(string $name): void
    {
        $key = CardanoSignerVectors::key($name);
        $other = CardanoSignerVectors::key($name === 'payment' ? 'policy' : 'payment');
        $message = str_repeat("\x42", 32);
        $signature = $key->sign($message);

        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, $message, $key->publicKey()));
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $message."\x00", $key->publicKey()));

        $tamperedMessage = $message;
        $tamperedMessage[0] = "\x43";
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $tamperedMessage, $key->publicKey()));

        foreach ([0, 31, 32, 63] as $byte) {
            $flipped = $signature;
            $flipped[$byte] = chr(ord($flipped[$byte]) ^ 0x01);
            $this->assertFalse(
                sodium_crypto_sign_verify_detached($flipped, $message, $key->publicKey()),
                'A signature with byte '.$byte.' flipped still verified.'
            );
        }

        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $message, $other->publicKey()));
        $this->assertNotSame(bin2hex($signature), bin2hex($other->sign($message)));

        $key->discard();
        $other->discard();
    }

    /**
     * Both labels cardano-signer writes are read, and nothing else is.
     */
    public function test_only_the_two_extended_labels_are_read(): void
    {
        $this->assertSame(
            'PaymentExtendedSigningKeyShelley_ed25519_bip32',
            json_decode(CardanoSignerVectors::file('payment.skey'), true)['type']
        );
        $this->assertSame(
            'ExtendedSigningKeyShelley_ed25519_bip32',
            json_decode(CardanoSignerVectors::file('policy.skey'), true)['type']
        );

        $envelope = json_decode(CardanoSignerVectors::file('payment.skey'), true);
        $envelope['type'] = 'PaymentSigningKeyShelley_ed25519';

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('is not an extended signing key');

        SigningKey::fromExtendedTextEnvelope((string) json_encode($envelope));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedEnvelopes(): array
    {
        $cborHex = json_decode(CardanoSignerVectors::file('payment.skey'), true)['cborHex'];
        $wrap = static fn (string $hex): string => (string) json_encode([
            'type' => 'PaymentExtendedSigningKeyShelley_ed25519_bip32',
            'description' => '',
            'cborHex' => $hex,
        ]);

        // The embedded verification key replaced with the policy key's.
        $policyKey = CardanoSignerVectors::entry('policy')['public_key'];
        $swapped = substr($cborHex, 0, 4 + 128).$policyKey.substr($cborHex, 4 + 192);

        return [
            'not JSON' => ['{', 'must be JSON'],
            'no cborHex' => [(string) json_encode(['type' => 'PaymentExtendedSigningKeyShelley_ed25519_bip32']), 'a type and a cborHex'],
            'short' => [$wrap(substr($cborHex, 0, -2)), '5880 followed by 128 bytes'],
            'wrong CBOR head' => [$wrap('5840'.substr($cborHex, 4)), '5880 followed by 128 bytes'],
            'not hex' => [$wrap(substr($cborHex, 0, -1).'z'), '5880 followed by 128 bytes'],
            'another key embedded' => [$wrap($swapped), 'is not the one this extended key computes'],
        ];
    }

    /**
     * A damaged file is refused, and the refusal never quotes the secret.
     */
    #[DataProvider('malformedEnvelopes')]
    public function test_a_malformed_key_file_is_refused_without_quoting_it(string $json, string $reason): void
    {
        [$kL, $kR] = self::halves('payment');

        try {
            SigningKey::fromExtendedTextEnvelope($json);
            $this->fail('A malformed key file was accepted.');
        } catch (SigningException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
            $this->assertStringNotContainsString(bin2hex($kL), $e->getMessage());
            $this->assertStringNotContainsString(bin2hex($kR), $e->getMessage());
            $this->assertStringNotContainsString(bin2hex($kL), $e->getTraceAsString());
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function badHalves(): array
    {
        $kL = str_repeat("\x08", 32);

        return [
            'short kL' => [str_repeat("\x08", 31), str_repeat("\x01", 32), 'kL) is 32 bytes, got 31'],
            'long kR' => [$kL, str_repeat("\x01", 33), 'kR) is 32 bytes, got 33'],
            'kL not a multiple of eight' => [str_repeat("\x01", 32), str_repeat("\x01", 32), 'multiple of eight'],
            'kL of zero' => [str_repeat("\x00", 32), str_repeat("\x01", 32), 'multiple of the group order'],
        ];
    }

    #[DataProvider('badHalves')]
    public function test_halves_that_are_not_an_extended_key_are_refused(string $kL, string $kR, string $reason): void
    {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage($reason);

        SigningKey::fromExtended($kL, $kR);
    }

    public function test_a_verification_key_of_the_wrong_length_is_refused(): void
    {
        [$kL, $kR] = self::halves('payment');

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('verification key is 32 bytes, got 31');

        SigningKey::fromExtended($kL, $kR, str_repeat("\x00", 31));
    }

    /**
     * An extended key keeps its secret the way a seed key does: not in a property, not in a dump, not serialized,
     * not cloned, and gone after a discard.
     */
    public function test_an_extended_key_withholds_its_secret(): void
    {
        [$kL, $kR] = self::halves('payment');
        $key = CardanoSignerVectors::key('payment');
        $secret = $this->secretOf($key);

        $this->assertSame($kL.$kR, $secret);

        ob_start();
        var_dump($key);
        $dumped = (string) ob_get_clean();

        foreach ([
            'json_encode' => (string) json_encode($key),
            'print_r' => print_r($key, true),
            'var_export' => var_export($key, true),
            'var_dump' => $dumped,
        ] as $renderer => $text) {
            foreach (['kL' => $kL, 'kR' => $kR] as $half => $bytes) {
                $this->assertStringNotContainsString($bytes, $text, $renderer.' printed '.$half.'.');
                $this->assertStringNotContainsString(bin2hex($bytes), $text, $renderer.' printed '.$half.' as hex.');
            }
        }

        $this->assertStringContainsString('(withheld)', print_r($key, true));
        $this->assertStringContainsString('ed25519-bip32', print_r($key, true));

        try {
            serialize($key);
            $this->fail('An extended key was serialized.');
        } catch (LogicException) {
        }

        try {
            $copy = clone $key;
            $this->fail('An extended key was cloned.');
        } catch (LogicException) {
        }

        $key->discard();

        $this->assertSame('', $this->secretOf($key));

        $this->expectException(SigningException::class);
        $key->sign('x');
    }

    /**
     * The two halves of a key file, read directly rather than through the code under test.
     *
     * @return array{string, string}
     */
    private static function halves(string $name): array
    {
        $cborHex = json_decode(CardanoSignerVectors::file($name.'.skey'), true)['cborHex'];
        $bytes = (string) hex2bin(substr($cborHex, 4));

        return [substr($bytes, 0, 32), substr($bytes, 32, 32)];
    }

    /**
     * What libsodium does to a seed before signing with it: the clamped first half of its SHA-512 and the second.
     *
     * @return array{string, string}
     */
    private static function expand(string $seed): array
    {
        $hash = hash('sha512', $seed, true);
        $kL = substr($hash, 0, 32);
        $kL[0] = chr(ord($kL[0]) & 248);
        $kL[31] = chr((ord($kL[31]) & 127) | 64);

        return [$kL, substr($hash, 32)];
    }

    private function secretOf(SigningKey $key): string
    {
        $signer = (new \ReflectionProperty(SigningKey::class, 'signer'))->getValue($key);

        return (new \ReflectionFunction($signer))->getClosureUsedVariables()['secretKey'];
    }
}
