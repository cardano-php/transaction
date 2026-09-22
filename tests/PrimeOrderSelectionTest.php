<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Signing\Edwards25519;
use Cardano\Transaction\Signing\SigningKey;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SodiumException;

/**
 * Choosing R, and the verification key, out of the four points a ristretto255 encoding stands for.
 *
 * The signer asks libsodium which candidate is in the prime-order subgroup, through
 * sodium_crypto_sign_ed25519_pk_to_curve25519(), and keeps that one. It never computes a signature for a candidate
 * it does not keep, so the choice has to be right from public data alone. These tests check that exactly one
 * candidate passes libsodium's test every time and that it is the point a reference computes another way.
 */
class PrimeOrderSelectionTest extends TestCase
{
    /**
     * A clamped scalar c, as libsodium makes one from a seed, has c times the base point as the public key libsodium
     * returns. So for 256 seeds the reference is libsodium's own Ed25519 base point multiplication, and the candidates
     * come from the ristretto255 one. The small-order part lands on each of the four candidates about equally often,
     * so 256 runs reach every position many times.
     */
    public function test_exactly_one_candidate_passes_and_it_is_libsodiums_point(): void
    {
        $positions = [0, 0, 0, 0];

        for ($i = 0; $i < 256; $i++) {
            $seed = hash('sha256', 'cardano-php/transaction prime-order selection '.$i, true);
            $expected = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));

            $hash = hash('sha512', $seed, true);
            $clamped = substr($hash, 0, 32);
            $clamped[0] = chr(ord($clamped[0]) & 248);
            $clamped[31] = chr((ord($clamped[31]) & 127) | 64);
            $ristretto = sodium_crypto_scalarmult_ristretto255_base(
                sodium_crypto_core_ristretto255_scalar_reduce(str_pad($clamped, 64, "\x00"))
            );

            $candidates = Edwards25519::candidates($ristretto);
            $passing = array_values(array_filter($candidates, self::inPrimeOrderSubgroup(...)));

            $this->assertCount(1, $passing, 'Seed '.$i.': not exactly one candidate passed.');
            $this->assertSame(bin2hex($expected), bin2hex($passing[0]), 'Seed '.$i.': the passing candidate is not A.');
            $this->assertSame(bin2hex($expected), bin2hex(self::choose($ristretto)), 'Seed '.$i.': the signer chose another.');

            $positions[array_search($expected, $candidates, true)]++;
        }

        foreach ($positions as $position => $count) {
            $this->assertGreaterThan(20, $count, 'The right point was candidate '.$position.' only '.$count.' times.');
        }
    }

    /**
     * For scalars spread over the whole range rather than clamped ones, the reference is P - [L]P, computed from the
     * curve equation.
     */
    public function test_the_chosen_point_is_the_reference_for_unclamped_scalars(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $scalar = sodium_crypto_core_ristretto255_scalar_reduce(
                hash('sha512', 'cardano-php/transaction unclamped scalar '.$i, true)
            );
            $ristretto = sodium_crypto_scalarmult_ristretto255_base($scalar);

            $this->assertSame(bin2hex(EdwardsReference::primeOrderPoint($ristretto)), bin2hex(self::choose($ristretto)));
        }
    }

    /**
     * The RFC 9496 multiples of the generator come out as k times the base point, and the identity, which has no
     * member outside the small-order points, is refused.
     */
    public function test_the_published_multiples_choose_k_times_the_base_point(): void
    {
        foreach (JsonFixture::read('ristretto255/rfc9496-vectors.json')['multiples_of_the_generator'] as $vector) {
            if ($vector['multiple'] === 0) {
                continue;
            }

            $this->assertSame(
                bin2hex(EdwardsReference::multiplyBase($vector['multiple'])),
                bin2hex(self::choose((string) hex2bin($vector['encoding'])))
            );
        }

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('found 0');

        self::choose(str_repeat("\x00", 32));
    }

    private static function inPrimeOrderSubgroup(string $point): bool
    {
        try {
            sodium_crypto_sign_ed25519_pk_to_curve25519($point);

            return true;
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * The signer's own choice, reached through reflection because it is private to the class that holds keys.
     */
    private static function choose(string $ristretto): string
    {
        return (new ReflectionMethod(SigningKey::class, 'primeOrderMember'))->invoke(null, $ristretto);
    }
}
