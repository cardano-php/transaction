<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Ledger\WitnessPlan;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\WitnessSet;
use Cardano\Transaction\Signing\SigningKey;
use Cardano\Transaction\Signing\TransactionSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Signing, closed back onto the transactions the read path was proved against.
 *
 * The corpus is real mainnet traffic, fetched by hash and committed with its provenance. The decoder was proved
 * against it four ways, and one of those four is the assertion that matters here: every vkey witness on every one of
 * these transactions verifies against the body hash **this package computes**, and those signatures were made years
 * ago by keys nobody here has ever held. That fixes what a Cardano witness covers, from outside.
 *
 * What this file adds is the other direction. For each of those transactions, the body is rebuilt out of its decoded
 * model through the builder this step introduces, signed with a key that exists for the length of one test, and the
 * resulting witness is checked against the hash of the rebuilt body. Passing means the builder, the hash and the
 * signer agree with each other over the same shapes the chain already agreed with the decoder over: multi-asset
 * outputs, both validity bounds, mints, collateral, required signers, legacy and map form outputs, and transactions
 * from before the script validity flag existed.
 *
 * Doing it on invented bodies would prove much less. A body built here is a body built the way this code likes to
 * build them, and the shapes that break a builder are the ones nobody thought to invent.
 *
 * No key here is written anywhere. Each is generated inside the test that uses it and discarded before it returns,
 * and no fixture is modified: the signing happens on a rebuilt copy.
 */
class SignedCorpusTest extends TestCase
{
    public static function chainFixtures(): array
    {
        return TransactionFixtures::chainFixtures();
    }

    /**
     * Rebuild a corpus transaction's body, sign it, and check the witness against the rebuilt body's own hash.
     *
     * The rebuilt body is not always the fixture's body byte for byte, and it is not meant to be. The builder writes
     * a definite map with fields in ascending order, untagged sets and map form outputs, and a mainnet transaction
     * may have been written with tagged sets, legacy outputs or fields in another order. What has to hold is that
     * whatever the builder produced, the hash taken over it is the hash the signature covers.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_rebuilt_corpus_body_can_be_signed_and_the_witness_verifies(array $fixture): void
    {
        $original = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));
        $rebuilt = $this->rebuild($original->body);

        $key = SigningKey::generate();

        try {
            $witness = TransactionSigner::witness($rebuilt, $key);

            $this->assertTrue(
                $witness->verifies($rebuilt->hash()),
                $fixture['id'].': a witness made over the rebuilt body does not verify against its hash.'
            );

            $flipped = $rebuilt->hash();
            $flipped[0] = chr(ord($flipped[0]) ^ 0x01);

            $this->assertFalse(
                $witness->verifies($flipped),
                $fixture['id'].': the witness verified against a hash one bit away from the one it covers.'
            );

            // Where the rebuild came out as different bytes, the original's hash is a second message the witness
            // must not cover. Where it came out identical there is no second message, and asserting one would be
            // asserting a coincidence: eight of the corpus transactions were already written the way this builder
            // writes them, which is a result rather than a problem.
            if ($rebuilt->encode() !== $original->body->encode()) {
                $this->assertFalse(
                    $witness->verifies($original->body->hash()),
                    $fixture['id'].': the witness verified against a hash it was not made over.'
                );
            } else {
                $this->assertSame(
                    $original->body->hashHex(),
                    $rebuilt->hashHex(),
                    $fixture['id'].': identical bytes hashed to different values.'
                );
            }

            $this->assertSame($key->publicKeyHex(), $witness->vkeyHex());
        } finally {
            $key->discard();
        }
    }

    /**
     * The whole transaction, assembled and signed, and every witness on it verifies.
     *
     * The measuring step is included rather than skipped, because it is what happens in practice: the transaction is
     * assembled with witnesses of the right length holding zeroes, and this asserts both that the zeroes do not
     * verify and that what replaces them does, at the same size.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_rebuilt_corpus_transaction_assembles_measures_and_signs(array $fixture): void
    {
        $original = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));
        $body = $this->rebuild($original->body);

        $unsigned = Transaction::assemble(
            $body,
            WitnessSet::of(WitnessPlan::forSignatures(1)->dummyWitnesses()),
            $original->auxiliaryData,
        );

        $this->assertFalse(
            TransactionSigner::witnessesVerify($unsigned),
            $fixture['id'].': a transaction carrying only measuring witnesses reported that they verify.'
        );

        $key = SigningKey::generate();

        try {
            $signed = TransactionSigner::sign($unsigned, $key);

            $this->assertTrue(
                TransactionSigner::witnessesVerify($signed),
                $fixture['id'].': the signed transaction does not verify against its own body hash.'
            );

            $this->assertSame(
                $unsigned->hashHex(),
                $signed->hashHex(),
                $fixture['id'].': signing moved the transaction hash.'
            );

            $this->assertSame(
                strlen($unsigned->encode()),
                strlen($signed->encode()),
                $fixture['id'].': the signed transaction is not the size it was measured at.'
            );

            $this->assertSame(
                bin2hex($body->encode()),
                bin2hex(TransactionDecoder::decode($signed->encode())->body->encode()),
                $fixture['id'].': the body did not survive a round trip through the signed transaction.'
            );
        } finally {
            $key->discard();
        }
    }

    /**
     * Auxiliary data carried over from a fixture still hashes to what the rebuilt body says it does.
     *
     * The builder is handed the auxiliary data rather than a hash of it, so the two cannot drift; this is the
     * assertion that they have not, over real metadata rather than metadata written to suit the test.
     */
    #[DataProvider('chainFixtures')]
    public function test_auxiliary_data_carried_into_a_rebuilt_body_still_matches_its_hash(array $fixture): void
    {
        $original = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        if ($original->auxiliaryData === null) {
            $this->assertNull(
                $this->rebuild($original->body)->auxiliaryDataHash(),
                $fixture['id'].' has no auxiliary data, so a rebuilt body must carry no hash for any.'
            );

            return;
        }

        $rebuilt = $this->rebuild($original->body);

        $this->assertSame(
            bin2hex((string) $rebuilt->auxiliaryDataHash()),
            $original->auxiliaryData->hashHex(),
            $fixture['id'].': the rebuilt body does not carry the hash of the auxiliary data attached to it.'
        );

        $assembled = Transaction::assemble(
            $rebuilt,
            WitnessSet::of([]),
            $original->auxiliaryData,
        );

        $this->assertSame(
            $original->auxiliaryData->hashHex(),
            bin2hex((string) $assembled->body->auxiliaryDataHash()),
            $fixture['id'].': assembly separated the auxiliary data from its hash.'
        );
    }

    /**
     * Rebuild a decoded body through the builder.
     *
     * Only the fields this step's builder owns are carried across. A corpus transaction may hold certificates,
     * withdrawals or governance fields, which the builder does not write and which are therefore dropped from the
     * copy; that is what makes this a rebuild rather than a round trip, and the copy is still a body of exactly the
     * kind the signing path has to handle.
     */
    private function rebuild(TransactionBody $body): TransactionBody
    {
        $builder = TransactionBodyBuilder::create()
            ->inputs($body->inputs())
            ->outputs($body->outputs())
            ->fee($body->fee()->value);

        if ($body->ttl() !== null) {
            $builder->ttl($body->ttl()->value);
        }

        if ($body->validityIntervalStart() !== null) {
            $builder->validityIntervalStart($body->validityIntervalStart()->value);
        }

        if ($body->auxiliaryDataHash() !== null) {
            $builder->auxiliaryDataHash($body->auxiliaryDataHash());
        }

        if ($body->mint() !== null) {
            $builder->mint($body->mint());
        }

        if ($body->requiredSigners() !== []) {
            $builder->requiredSigners($body->requiredSigners());
        }

        if ($body->networkId() !== null) {
            $builder->networkId($body->networkId()->toInt());
        }

        if ($body->collateral() !== []) {
            $builder->collateral($body->collateral());
        }

        if ($body->referenceInputs() !== []) {
            $builder->referenceInputs($body->referenceInputs());
        }

        return $builder->build();
    }

    /**
     * The corpus really does carry the shapes this file claims to have signed over.
     *
     * Without this, the two tests above would still pass on a corpus that had quietly shrunk to one plain payment,
     * and the claim that the builder was exercised against multi-asset outputs, mints and validity bounds would be
     * an assertion about a directory listing rather than about anything.
     */
    public function test_the_corpus_covers_the_shapes_this_file_claims(): void
    {
        $seen = ['assets' => false, 'bounds' => false, 'legacy_output' => false, 'map_output' => false, 'mint' => false];

        foreach (TransactionFixtures::section('chain') as $fixture) {
            $body = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body;

            $seen['mint'] = $seen['mint'] || $body->mint() !== null;
            $seen['bounds'] = $seen['bounds'] || ($body->ttl() !== null && $body->validityIntervalStart() !== null);

            foreach ($body->outputs() as $output) {
                $seen['assets'] = $seen['assets'] || $output->value->hasAssets();
                $seen['legacy_output'] = $seen['legacy_output'] || $output->form === 'legacy';
                $seen['map_output'] = $seen['map_output'] || $output->form === 'map';
            }
        }

        foreach ($seen as $shape => $found) {
            $this->assertTrue($found, 'No corpus transaction carries a '.$shape.', so nothing here signed over one.');
        }
    }
}
