<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Hash\Blake2b;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one transaction here that was never on chain.
 *
 * A suite built only from real transactions can be passed by a decoder that learned the answers. This one has no
 * answers to learn: its body differs from the fixture it came from by a single lovelace, so the hash the decoder
 * returns has to be one it computed, and the signature that was made over the original body has to fail against it.
 * Assertion three is only worth running if it can fail, and this is where it does.
 */
class PerturbedTransactionTest extends TestCase
{
    public static function perturbedFixtures(): array
    {
        return TransactionFixtures::perturbedFixtures();
    }

    #[DataProvider('perturbedFixtures')]
    public function test_a_changed_body_produces_a_different_hash(array $fixture): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        $this->assertNotSame($fixture['original_hash'], $transaction->hashHex());
        $this->assertSame($fixture['expected_hash'], $transaction->hashHex());
        $this->assertSame(
            bin2hex(Blake2b::hash256($transaction->body->encode())),
            $transaction->hashHex()
        );
    }

    #[DataProvider('perturbedFixtures')]
    public function test_a_changed_body_still_round_trips(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);

        $this->assertSame(bin2hex($bytes), bin2hex(TransactionDecoder::decode($bytes)->encode()));
    }

    #[DataProvider('perturbedFixtures')]
    public function test_the_witness_from_the_original_body_does_not_verify(array $fixture): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));
        $witnesses = $transaction->witnessSet->vkeyWitnesses();

        $this->assertNotEmpty($witnesses);

        $digest = $transaction->hash();
        foreach ($witnesses as $witness) {
            $this->assertFalse(
                $witness->verifies($digest),
                'A signature over the original body verified against the changed one.'
            );
        }
    }

    #[DataProvider('perturbedFixtures')]
    public function test_the_original_still_verifies(array $fixture): void
    {
        $origin = TransactionFixtures::chainFixture($fixture['derived_from']);
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($origin['file']));

        $digest = $transaction->hash();
        foreach ($transaction->witnessSet->vkeyWitnesses() as $witness) {
            $this->assertTrue($witness->verifies($digest));
        }
    }
}
