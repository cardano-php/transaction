<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Signing\SigningKey;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * A key that exists for the length of one call and leaves nothing behind.
 *
 * Custody is a later step and arrives with encryption at rest, a recovery record and a migration for the columns to
 * put it in. Nothing in this step holds a key past the end of a function, and the assertions below are what stops
 * that becoming untrue by accident: serialization, cloning and dumping are all refused, because each of them is a
 * route from an object in memory to bytes in a cache, a log or a session that nothing in this package could then
 * reach to wipe.
 */
class SigningKeyTest extends TestCase
{
    public function test_a_generated_key_is_a_different_key_every_time(): void
    {
        $first = SigningKey::generate();
        $second = SigningKey::generate();

        $this->assertNotSame($first->publicKeyHex(), $second->publicKeyHex());
        $this->assertSame(32, strlen($first->publicKey()));

        $first->discard();
        $second->discard();
    }

    /**
     * A verification key hashes to the credential an address and a required signer field carry.
     */
    public function test_a_key_yields_the_credential_an_address_is_built_from(): void
    {
        $key = SigningKey::generate();

        $this->assertSame(
            bin2hex(Blake2b::hash224($key->publicKey())),
            $key->credential()->hex()
        );

        $this->assertFalse($key->credential()->isScript());

        $key->discard();
    }

    /**
     * Once discarded, a key refuses to sign rather than signing with whatever is left in the buffer.
     */
    public function test_a_discarded_key_refuses_to_sign(): void
    {
        $key = SigningKey::generate();
        $key->discard();

        $this->assertTrue($key->isDiscarded());

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('This key has been discarded and cannot sign.');

        $key->sign(str_repeat("\x00", 32));
    }

    /**
     * Discarding twice is not an error, because a caller that discards in a finally block and a destructor that
     * discards on collection would otherwise have to know about each other.
     */
    public function test_discarding_twice_is_harmless(): void
    {
        $key = SigningKey::generate();

        $key->discard();
        $key->discard();

        $this->assertTrue($key->isDiscarded());
    }

    /**
     * The public key survives discarding, because it was never a secret.
     *
     * The identity a transaction was signed under is what a recovery record and a witness both name, and losing it
     * along with the secret would mean not being able to say which key had signed something.
     */
    public function test_the_public_key_survives_discarding(): void
    {
        $key = SigningKey::generate();
        $before = $key->publicKeyHex();

        $key->discard();

        $this->assertSame($before, $key->publicKeyHex());
    }

    public function test_a_key_cannot_be_serialized(): void
    {
        $key = SigningKey::generate();

        try {
            $this->expectException(LogicException::class);
            serialize($key);
        } finally {
            $key->discard();
        }
    }

    public function test_a_key_cannot_be_cloned(): void
    {
        $key = SigningKey::generate();

        try {
            $this->expectException(LogicException::class);
            clone $key;
        } finally {
            $key->discard();
        }
    }

    /**
     * Nothing that renders the object renders the secret.
     *
     * var_dump and print_r go through __debugInfo. var_export does not: it walks an object's real properties and
     * prints whatever is in them, which is why the secret is captured inside a closure rather than held in one. A
     * 64 byte random secret contains a quote or a backslash about two times in five, and var_export escapes those,
     * so a test that only checked a raw substring would have passed three runs in five with the key in plain view;
     * the fixed secret below removes the coin toss.
     */
    public function test_dumping_a_key_does_not_print_the_secret(): void
    {
        $key = SigningKey::fromSeed(str_repeat("\x5c", 32));
        $secret = $this->secretOf($key);

        $this->assertSame(64, strlen($secret), 'The seed did not expand to a 64 byte secret key.');

        foreach ([
            'json_encode' => (string) json_encode($key),
            'print_r' => print_r($key, true),
            'var_export' => var_export($key, true),
        ] as $renderer => $text) {
            $this->assertStringNotContainsString($secret, $text, $renderer.' printed the secret key.');
            $this->assertStringNotContainsString(bin2hex($secret), $text, $renderer.' printed the secret key as hex.');
        }

        ob_start();
        var_dump($key);
        $dumped = (string) ob_get_clean();

        $this->assertStringNotContainsString($secret, $dumped, 'var_dump printed the secret key.');
        $this->assertStringContainsString('(withheld)', print_r($key, true));
        $this->assertSame('{}', (string) json_encode($key));

        $key->discard();
    }

    /**
     * Discarding zeroes what the object holds.
     *
     * Read through reflection, which is the only thing that can see it at all, and the point of the assertion: after
     * a discard there is nothing left in there to read.
     */
    public function test_discarding_clears_the_secret_the_object_holds(): void
    {
        $key = SigningKey::generate();

        $this->assertSame(64, strlen($this->secretOf($key)));

        $key->discard();

        $this->assertSame('', $this->secretOf($key));
    }

    /**
     * Two keys built from the same seed are the same key, which is the property the RFC vectors depend on and the
     * reason a campaign key is generated rather than derived from anything.
     */
    public function test_the_same_seed_gives_the_same_key(): void
    {
        $seed = random_bytes(32);

        $first = SigningKey::fromSeed($seed);
        $second = SigningKey::fromSeed($seed);

        $message = random_bytes(32);

        $this->assertSame($first->publicKeyHex(), $second->publicKeyHex());
        $this->assertSame(bin2hex($first->sign($message)), bin2hex($second->sign($message)));

        $first->discard();
        $second->discard();
    }

    /**
     * The secret, dug out of the closure that holds it.
     *
     * This is the deliberate act the class allows and the debug helpers do not: reflection can reach a closure's
     * captured variables. Nothing outside a test has any reason to do it, and it is done here so the assertions
     * above are comparing against the real key rather than against something believed to be it.
     */
    private function secretOf(SigningKey $key): string
    {
        $signer = (new \ReflectionProperty(SigningKey::class, 'signer'))->getValue($key);

        return (new \ReflectionFunction($signer))->getClosureUsedVariables()['secretKey'];
    }
}
