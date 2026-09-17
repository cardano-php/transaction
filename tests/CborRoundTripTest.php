<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Whether the decoder preserves what it read, over far more documents than the corpus holds.
 *
 * A transaction hash is blake2b over the body's bytes, so the one property the CBOR layer has to have is that
 * decoding and re-encoding gives back exactly what arrived. The committed corpus checks that against real mainnet
 * traffic, which is the part that matters most and the part that cannot be invented. What it cannot check is the
 * encodings mainnet does not happen to use: every transaction in the scanned pool writes the shortest head for every
 * integer, every length and every count, so nothing on chain says what happens to a wider one.
 *
 * These documents are generated from a fixed seed, so the suite is the same run to run and a failure names a
 * document that can be reproduced. They deliberately write heads wider than they need to, mix definite and
 * indefinite framings at every level, and nest maps, arrays, tags and chunked strings inside one another.
 *
 * Two shapes are generated, because the two layers need different documents. Arbitrary::document is a document of
 * no particular shape, which is what holds the CBOR layer to reading and writing anything well formed. Nothing of
 * that shape is ever a transaction, so the transaction layer gets GeneratedTransaction, which fixes the shape and
 * varies the writing; a case that fed the first kind to the second layer would count two thousand refusals and
 * never reach the question it was asked.
 *
 * The other property is the refusals: a decoder handed bytes that are not CBOR, or a transaction with one thing
 * about it wrong, says so. Anything else, from a truncated structure handed back as if it were whole to an error
 * that is not a DecodeException, is a failure.
 */
class CborRoundTripTest extends TestCase
{
    /** How many documents each generated case holds. */
    private const DOCUMENTS = 250;

    /**
     * @return array<string, array{int}>
     */
    public static function seeds(): array
    {
        $cases = [];

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $seed) {
            $cases['seed '.$seed] = [$seed];
        }

        return $cases;
    }

    /**
     * Every generated document comes back byte for byte, including the widths and framings it was written with.
     */
    #[DataProvider('seeds')]
    public function test_a_generated_document_is_written_back_the_way_it_arrived(int $seed): void
    {
        mt_srand($seed);

        for ($index = 0; $index < self::DOCUMENTS; $index++) {
            $bytes = Arbitrary::document(0);

            $this->assertSame(
                bin2hex($bytes),
                bin2hex(CborCodec::encode(CborCodec::decode($bytes))),
                sprintf('Seed %d document %d does not survive a round trip.', $seed, $index)
            );
        }
    }

    /**
     * And a second pass over the same document reaches the same bytes, so the model has settled rather than drifted.
     */
    #[DataProvider('seeds')]
    public function test_decoding_what_was_encoded_reaches_the_same_bytes_again(int $seed): void
    {
        mt_srand($seed + 1000);

        for ($index = 0; $index < self::DOCUMENTS; $index++) {
            $bytes = Arbitrary::document(0);
            $once = CborCodec::encode(CborCodec::decode($bytes));
            $twice = CborCodec::encode(CborCodec::decode($once));

            $this->assertSame(bin2hex($once), bin2hex($twice));
        }
    }

    /**
     * Bytes that are not CBOR are refused by name, never returned as a structure and never as something else thrown.
     */
    #[DataProvider('seeds')]
    public function test_bytes_that_are_not_cbor_are_refused_rather_than_read(int $seed): void
    {
        mt_srand($seed + 2000);

        $refused = 0;

        for ($index = 0; $index < self::DOCUMENTS; $index++) {
            $bytes = Arbitrary::corrupted(Arbitrary::document(0));

            try {
                $decoded = CborCodec::decode($bytes);
            } catch (DecodeException) {
                $refused++;

                continue;
            } catch (Throwable $e) {
                $this->fail(sprintf(
                    'Seed %d document %d was refused with %s rather than a DecodeException: %s',
                    $seed,
                    $index,
                    $e::class,
                    $e->getMessage()
                ));
            }

            // A corruption that still leaves valid CBOR is a different document, not a failure. It still has to be
            // a document the decoder can write back out unchanged.
            $this->assertSame(bin2hex($bytes), bin2hex(CborCodec::encode($decoded)));
        }

        $this->assertGreaterThan(
            0,
            $refused,
            'No corrupted document was refused, so this test is not exercising the refusals.'
        );
    }

    /**
     * Transaction-shaped documents through the transaction layer, which is where the hash is computed.
     *
     * The three cases above stop at CborCodec, and CborCodec is the layer with nothing in it that takes a
     * transaction apart: it holds the head of every item it reads and hands all of them back. The layer above it
     * reads fields out into a model and rebuilds them, and a document that the codec reproduces can still come back
     * from there as different bytes.
     *
     * Reaching that layer needs documents that are transactions. A document shaped at random is a four item array
     * with a map body about as often as never, so routing one through here exercises the refusal and nothing else.
     * These are built to the shape instead and varied in the writing: every head at a width chosen from the ones
     * that hold it, containers definite or indefinite, sets with and without tag 258, body and witness fields in
     * arbitrary order, and arbitrary CBOR in the fields this package carries through rather than models. All of that
     * is in the bytes the ledger hashed and none of it changes what the transaction says, which is exactly the set
     * of differences a decoder is most likely to normalise away.
     *
     * So the assertion is the strong one: every document is accepted, and every one comes back byte for byte.
     */
    #[DataProvider('seeds')]
    public function test_a_generated_transaction_is_accepted_and_written_back_the_way_it_arrived(int $seed): void
    {
        mt_srand($seed + 3000);

        $accepted = 0;

        for ($index = 0; $index < self::DOCUMENTS; $index++) {
            $bytes = GeneratedTransaction::bytes();

            try {
                $transaction = TransactionDecoder::decode($bytes);
            } catch (Throwable $e) {
                $this->fail(sprintf(
                    'Seed %d document %d is a transaction and was refused with %s: %s. The document was %s.',
                    $seed,
                    $index,
                    $e::class,
                    $e->getMessage(),
                    bin2hex($bytes)
                ));
            }

            $accepted++;

            $this->assertSame(
                bin2hex($bytes),
                bin2hex($transaction->encode()),
                sprintf('Seed %d document %d was accepted and written back as different bytes.', $seed, $index)
            );

            $this->assertSame(
                bin2hex(Blake2b::hash256(CborCodec::encode(CborCodec::decode($bytes)->items()[0]))),
                $transaction->hashHex(),
                sprintf('Seed %d document %d hashed to a transaction other than the one handed in.', $seed, $index)
            );
        }

        $this->assertSame(
            self::DOCUMENTS,
            $accepted,
            'Not every generated transaction reached the assertion, so the count below says less than it looks.'
        );
    }

    /**
     * And the same documents with one thing about them wrong are refused rather than read.
     *
     * A generator that only ever produces valid documents says nothing about the refusals, and a case whose only
     * executed assertion is that something was refused says nothing about the acceptances. Both halves are here and
     * each is counted, so neither can quietly stop running.
     */
    #[DataProvider('seeds')]
    public function test_a_generated_transaction_with_one_thing_wrong_is_refused(int $seed): void
    {
        mt_srand($seed + 4000);

        $refused = 0;

        for ($index = 0; $index < self::DOCUMENTS; $index++) {
            $bytes = GeneratedTransaction::spoiled();

            try {
                TransactionDecoder::decode($bytes);
            } catch (DecodeException) {
                $refused++;

                continue;
            } catch (Throwable $e) {
                $this->fail(sprintf(
                    'Seed %d document %d was refused with %s rather than a DecodeException: %s',
                    $seed,
                    $index,
                    $e::class,
                    $e->getMessage()
                ));
            }

            $this->fail(sprintf(
                'Seed %d document %d is not a transaction and was accepted: %s',
                $seed,
                $index,
                bin2hex($bytes)
            ));
        }

        $this->assertSame(self::DOCUMENTS, $refused);
    }
}
