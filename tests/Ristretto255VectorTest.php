<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Signing\Edwards25519;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ristretto255 decoder the extended signing path depends on, against the vectors RFC 9496 publishes.
 *
 * An extended signature gets its commitment R, and an extended key its verification key, from libsodium as a
 * ristretto255 encoding, and Edwards25519 turns that into the Edwards point a signature carries. A decoder that
 * accepted what the RFC says to refuse, or landed on the wrong member of the coset, would produce signatures that
 * fail to verify, and the signing path would refuse to hand them out. These vectors find it first, and say where.
 */
class Ristretto255VectorTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function multiples(): array
    {
        $cases = [];

        foreach (JsonFixture::read('ristretto255/rfc9496-vectors.json')['multiples_of_the_generator'] as $vector) {
            $cases['B['.$vector['multiple'].']'] = [$vector['multiple'], $vector['encoding']];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalid(): array
    {
        $cases = [];

        foreach (JsonFixture::read('ristretto255/rfc9496-vectors.json')['invalid_encodings'] as $i => $vector) {
            $cases[$vector['reason'].' '.$i] = [$vector['reason'], $vector['encoding']];
        }

        return $cases;
    }

    /**
     * Each published multiple decodes, and the member of its coset in the prime-order subgroup is k times the base
     * point, computed from the curve equation with nothing from ristretto255 involved.
     */
    #[DataProvider('multiples')]
    public function test_each_multiple_of_the_generator_decodes_to_that_multiple(int $k, string $encoding): void
    {
        $expected = bin2hex(Edwards25519::multiplyBase($k));

        $this->assertSame($expected, bin2hex(Edwards25519::primeOrderPoint((string) hex2bin($encoding))));
        $this->assertContains($expected, array_map('bin2hex', Edwards25519::candidates((string) hex2bin($encoding))));
    }

    /**
     * libsodium's own ristretto255 base multiplication writes the published encodings, so what the signing path
     * hands the decoder is what the RFC describes.
     */
    #[DataProvider('multiples')]
    public function test_libsodium_writes_the_published_encoding(int $k, string $encoding): void
    {
        if ($k === 0) {
            // libsodium refuses to multiply by zero, because the identity is never a useful public value.
            $this->expectException(\SodiumException::class);
        }

        $scalar = str_pad(chr($k), 32, "\x00");

        $this->assertSame($encoding, bin2hex(sodium_crypto_scalarmult_ristretto255_base($scalar)));
    }

    /**
     * The base point comes out as the encoding RFC 8032 gives it, which anchors multiplyBase to something outside
     * this file.
     */
    public function test_the_base_point_has_its_rfc_8032_encoding(): void
    {
        $this->assertSame(
            '5866666666666666666666666666666666666666666666666666666666666666',
            bin2hex(Edwards25519::multiplyBase(1))
        );
        $this->assertSame(
            '0100000000000000000000000000000000000000000000000000000000000000',
            bin2hex(Edwards25519::multiplyBase(0))
        );
    }

    /**
     * Every encoding RFC 9496 says to refuse is refused, at the step the RFC names.
     */
    #[DataProvider('invalid')]
    public function test_every_invalid_encoding_is_refused(string $reason, string $encoding): void
    {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage(match ($reason) {
            'Non-canonical field encodings' => 'must be a canonical field element',
            'Negative field elements' => 'must be a non-negative field element',
            default => 'does not decode to a group element',
        });

        Edwards25519::decodeRistretto((string) hex2bin($encoding));
    }

    public function test_an_encoding_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('A ristretto255 encoding is 32 bytes, got 31.');

        Edwards25519::decodeRistretto(str_repeat("\x00", 31));
    }
}
