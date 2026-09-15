<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The negative corpus.
 *
 * Every transaction under chain/ is one the ledger accepted, so none of them can show that the decoder refuses
 * anything. These can. Each was made by changing one thing in a named chain fixture, which is why each test asserts
 * that the fixture it came from still decodes: otherwise a refusal could be coming from something else entirely.
 */
class TransactionRefusalTest extends TestCase
{
    public static function negativeFixtures(): array
    {
        return TransactionFixtures::negativeFixtures();
    }

    #[DataProvider('negativeFixtures')]
    public function test_a_negative_fixture_is_refused(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);

        try {
            TransactionDecoder::decode($bytes);
        } catch (DecodeException $e) {
            $this->assertNotSame('', $e->getMessage(), $fixture['id'].' was refused without saying why.');

            return;
        }

        $this->fail($fixture['id'].' was accepted. '.$fixture['must_be_refused_because']);
    }

    /**
     * The mutation is the only difference. If the original did not decode, the refusal would prove nothing.
     */
    #[DataProvider('negativeFixtures')]
    public function test_the_fixture_a_negative_was_derived_from_still_decodes(array $fixture): void
    {
        $origin = TransactionFixtures::chainFixture($fixture['derived_from']);

        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($origin['file']));

        $this->assertSame($origin['tx_hash'], $transaction->hashHex());
    }

    /**
     * Nothing outside the decoder should have to know which exception to catch.
     */
    #[DataProvider('negativeFixtures')]
    public function test_a_refusal_is_always_a_decode_exception(array $fixture): void
    {
        $this->expectException(DecodeException::class);

        TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));
    }

    public function test_hexadecimal_that_is_not_hexadecimal_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        TransactionDecoder::decodeHex('not hex');
    }

    public function test_empty_input_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        TransactionDecoder::decode('');
    }
}
