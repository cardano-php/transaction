<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the transaction layer does with a transaction written in an encoding mainnet does not happen to use.
 *
 * Every transaction in the corpus writes the shortest head for every integer, every length and every count, because
 * that is what cardano-cli and every other builder emits. So the corpus cannot say what happens to a wider one, and
 * the CBOR layer's own round trip tests cannot either: they stop at CborCodec, which is the layer with nothing in it
 * that takes a transaction apart. The hash is computed a layer above that, over a body rebuilt from the model, and
 * whatever that rebuild does not carry is lost between the two.
 *
 * Each case here takes a real mainnet transaction and writes one head one width wider. The payload, the argument and
 * the major type are untouched, so the document still means exactly what it meant; it is a well formed transaction
 * that hashes to something else. The decoder is then held to one thing: what it hands back has to be about the bytes
 * it was given. It may reproduce them, and it may refuse them. It may not answer with a hash belonging to a
 * transaction nobody sent.
 */
class TransactionHeadWidthTest extends TestCase
{
    /** How many mixed width rewrites of each fixture the generated case reads. */
    private const MIXED_WIDTH_ROUNDS = 16;

    public static function chainFixtures(): array
    {
        return TransactionFixtures::chainFixtures();
    }

    /**
     * The whole property, over every head of every committed transaction.
     *
     * A wrong answer here is not a test failure that shows up as a red build somewhere. It is a caller signing,
     * submitting or reporting a transaction hash that names something other than the bytes in their hand, with
     * nothing downstream able to tell.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_widened_head_is_reproduced_exactly_or_refused_and_never_answered_wrongly(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);
        $checked = 0;

        foreach (HeadWidths::heads($bytes) as $head) {
            $widened = HeadWidths::widened($bytes, $head);

            if ($widened === null) {
                continue;
            }

            $checked++;

            try {
                $transaction = TransactionDecoder::decode($widened);
            } catch (DecodeException) {
                continue;
            }

            $where = sprintf('%s, head at offset %d', $fixture['id'], $head['offset']);

            $this->assertSame(
                bin2hex($widened),
                bin2hex($transaction->encode()),
                $where.' was accepted and written back as different bytes.'
            );

            $this->assertSame(
                bin2hex(Blake2b::hash256(self::bodyBytes($widened))),
                $transaction->hashHex(),
                $where.' was accepted and hashed to a transaction other than the one handed in.'
            );
        }

        $this->assertGreaterThan(0, $checked, $fixture['id'].' has no head that can be written wider.');
    }

    /**
     * Every byte string in the body, at a head one width wider, comes back at that head.
     *
     * The body is what the transaction hash is taken over, and its byte strings are the fields the model takes
     * furthest apart: an output address, an input transaction id, the auxiliary data and script data hashes, the
     * required signers, a policy id, an asset name. Each is read out as a payload and written back from the model,
     * so each is a place where the head can be dropped on the floor. A refusal here would be safe and would still
     * be a transaction this package cannot read, so the assertion is the stronger one: accepted, byte for byte, and
     * hashing to the transaction that was handed in.
     */
    #[DataProvider('chainFixtures')]
    public function test_every_byte_string_head_in_a_body_is_carried_through_a_rebuild(array $fixture): void
    {
        $this->assertByteStringHeadsSurvive($fixture, 0, 'body');
    }

    /**
     * And the same through the witness set, which is the half that gets submitted rather than hashed.
     *
     * A transaction arriving from a partner or a CIP-30 wallet is decoded, counter-signed and handed back or
     * broadcast. The public keys and signatures in its witness set go through the same take-apart-and-rebuild as
     * the body does, so bytes that were not reproduced here would mean re-broadcasting something other than what
     * arrived, under a hash that no longer matches it.
     */
    #[DataProvider('chainFixtures')]
    public function test_every_byte_string_head_in_a_witness_set_is_carried_through_a_rebuild(array $fixture): void
    {
        $this->assertByteStringHeadsSurvive($fixture, 1, 'witness set');
    }

    /**
     * The widest a transaction can be written: every head of every container, string and integer at eight bytes.
     *
     * One head at a time says whether a position was forgotten. All of them at once says whether the positions
     * interact, which is the thing a list of cases cannot reach: an output address whose head is wider sits inside
     * an output whose head is wider, inside an output list whose head is wider, inside a body whose head is wider,
     * and each of those is a separate piece of recorded form that has to come back with the others. Nothing on
     * mainnet is written this way and nothing ever will be, which is the point.
     *
     * Major type 7 is left alone. Its heads do not state a length or a count, so writing one wider changes what the
     * item is rather than how it is spelt, and RFC 8949 section 3.3 makes most of those two byte forms not well
     * formed at all.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_transaction_written_entirely_at_the_widest_heads_is_still_itself(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);
        $widest = HeadWidths::rewritten($bytes, [0, 1, 2, 3, 4, 5, 6], static fn (array $head): int => 27);

        $this->assertNotSame($bytes, $widest, $fixture['id'].' has no head that can be written wider.');

        $transaction = TransactionDecoder::decode($widest);

        $this->assertSame(
            bin2hex($widest),
            bin2hex($transaction->encode()),
            $fixture['id'].' at the widest heads was not reproduced.'
        );
        $this->assertSame(
            bin2hex(Blake2b::hash256(self::bodyBytes($widest))),
            $transaction->hashHex(),
            $fixture['id'].' at the widest heads hashed to something else.'
        );
    }

    /**
     * And the same for heads picked at random from the widths that hold them, over a fixed seed.
     *
     * A document here mixes widths the way nothing real does: one length inline, the next in eight bytes, the one
     * after in two. Each is a transaction the ledger would accept with a hash of its own, and each is generated
     * rather than written down, so this reaches encodings nobody thought to list.
     */
    #[DataProvider('chainFixtures')]
    public function test_a_transaction_at_mixed_head_widths_is_reproduced_and_hashed_as_handed_in(array $fixture): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);

        for ($round = 0; $round < self::MIXED_WIDTH_ROUNDS; $round++) {
            mt_srand($round);

            $mixed = HeadWidths::rewritten($bytes, [0, 1, 2, 3, 4, 5, 6], static function (array $head): int {
                $widths = HeadWidths::widthsFor($head['argument']);

                return $widths[mt_rand(0, count($widths) - 1)];
            });

            $where = sprintf('%s, round %d', $fixture['id'], $round);
            $transaction = TransactionDecoder::decode($mixed);

            $this->assertSame(bin2hex($mixed), bin2hex($transaction->encode()), $where.' was not reproduced.');
            $this->assertSame(
                bin2hex(Blake2b::hash256(self::bodyBytes($mixed))),
                $transaction->hashHex(),
                $where.' was reproduced but hashed to something else.'
            );
        }
    }

    private function assertByteStringHeadsSurvive(array $fixture, int $topLevelItem, string $what): void
    {
        $bytes = TransactionFixtures::bytes($fixture['file']);
        [$start, $length] = HeadWidths::topLevelItems($bytes)[$topLevelItem];
        $checked = 0;

        foreach (HeadWidths::heads($bytes) as $head) {
            if ($head['major'] !== 2 || $head['offset'] < $start || $head['offset'] >= $start + $length) {
                continue;
            }

            $widened = HeadWidths::widened($bytes, $head);

            if ($widened === null) {
                continue;
            }

            $checked++;
            $where = sprintf('%s, %s byte string at offset %d', $fixture['id'], $what, $head['offset']);

            $transaction = TransactionDecoder::decode($widened);

            $this->assertSame(bin2hex($widened), bin2hex($transaction->encode()), $where.' was not reproduced.');
            $this->assertSame(
                bin2hex(Blake2b::hash256(self::bodyBytes($widened))),
                $transaction->hashHex(),
                $where.' was reproduced but hashed to something else.'
            );
        }

        $this->assertGreaterThan(0, $checked, $fixture['id'].' has no byte string in its '.$what.'.');
    }

    /**
     * The bytes of the body as they stand in the document, sliced out rather than rebuilt from any model of them.
     */
    private static function bodyBytes(string $transaction): string
    {
        [$offset, $length] = HeadWidths::topLevelItems($transaction)[0];

        return substr($transaction, $offset, $length);
    }
}
