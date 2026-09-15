<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Hash\Blake2b;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four known answer assertions, run over every committed mainnet transaction.
 *
 * Each one is checked against something the chain already decided, not against this decoder's own opinion. The hash
 * came back from the provider with the transaction; the signatures were made by keys we do not hold; the auxiliary
 * data hash was written into the body by whoever built it.
 */
class TransactionCorpusTest extends TestCase
{
    public static function chainFixtures(): array
    {
        return TransactionFixtures::chainFixtures();
    }

    /**
     * Assertion 1. Decode, re-encode, bytes identical.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_chain_fixture_re_encodes_to_the_bytes_it_was_decoded_from(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);

        $transaction = TransactionDecoder::decode($bytes);

        $this->assertSame(
            bin2hex($bytes),
            bin2hex($transaction->encode()),
            $fixture['id'].' does not survive a round trip.'
        );
    }

    /**
     * Assertion 2. Blake2b-256 over the body, re-encoded from the model, equals the hash it was fetched by.
     *
     * The body bytes are rebuilt by walking the decoded model, never sliced out of the input. The second assertion
     * below is what says so: the rebuilt body has to be findable inside the original transaction, which it can only
     * be if the model wrote back the same bytes in the same order.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_chain_fixture_hashes_to_the_value_it_was_fetched_by(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);

        $transaction = TransactionDecoder::decode($bytes);
        $body = $transaction->body->encode();

        $this->assertSame(
            $fixture['tx_hash'],
            $transaction->hashHex(),
            $fixture['id'].' hashes to something other than the hash Koios returned it under.'
        );

        $this->assertSame(bin2hex(Blake2b::hash256($body)), $transaction->hashHex());
        $this->assertStringContainsString(bin2hex($body), bin2hex($bytes));
    }

    /**
     * Assertion 3. Every vkey witness verifies against the computed body hash.
     *
     * This follows from assertion 2, since a transaction hash is blake2b-256 over its body and the signatures were
     * made over that. What it adds is that the witness set was taken apart correctly -- that the thing being read as
     * a public key is one -- and that the sodium call is wired the right way round. The perturbed fixture is what
     * shows the check can fail; see PerturbedTransactionTest.
     */
    #[DataProvider('chainFixtures')]
    public function test_every_vkey_witness_verifies_against_the_computed_body_hash(array $fixture): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));
        $witnesses = $transaction->witnessSet->vkeyWitnesses();

        $this->assertNotEmpty($witnesses, $fixture['id'].' carries no vkey witness, so this assertion says nothing.');

        $digest = $transaction->hash();
        foreach ($witnesses as $index => $witness) {
            $this->assertTrue(
                $witness->verifies($digest),
                sprintf('%s: witness %d (%s) does not verify.', $fixture['id'], $index, $witness->vkeyHex()),
            );
        }
    }

    /**
     * Assertion 4. Auxiliary data hashes to the value the body carries.
     *
     * A fixture with no metadata is not skipped. It asserts the other half of the same rule: no auxiliary data and no
     * hash for it, which is the pairing Transaction::fromCbor refuses to see broken.
     */
    #[DataProvider('chainFixtures')]
    public function test_auxiliary_data_hashes_to_the_value_in_the_body(array $fixture): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));
        $auxiliary = $transaction->auxiliaryDataBytes();

        if ($auxiliary === null) {
            $this->assertNull(
                $transaction->body->auxiliaryDataHash(),
                $fixture['id'].' has no auxiliary data but the body carries a hash for some.'
            );

            return;
        }

        $this->assertSame(
            bin2hex((string) $transaction->body->auxiliaryDataHash()),
            bin2hex(Blake2b::hash256($auxiliary)),
            $fixture['id'].': the auxiliary data does not hash to what the body says it does.'
        );
    }

    /**
     * The corpus is meant to be read offline. A fixture that reached for the network would pass here and fail in CI.
     */
    public function test_the_corpus_is_hexadecimal_text_on_disk(): void
    {
        foreach (TransactionFixtures::section('chain') as $fixture) {
            $path = TransactionFixtures::directory().'/'.$fixture['file'];
            $contents = trim((string) file_get_contents($path));

            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $contents, $path.' is not lower case hex.');
            $this->assertSame(0, strlen($contents) % 2, $path.' has an odd number of hex digits.');
            $this->assertSame(
                $fixture['bytes'],
                strlen($contents) / 2,
                $path.' is not the length the manifest records.'
            );
        }
    }
}
