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
     * The bytes of the body as they stand in the document, sliced out rather than rebuilt from any model of them.
     */
    private static function bodyBytes(string $transaction): string
    {
        [$offset, $length] = HeadWidths::topLevelItems($transaction)[0];

        return substr($transaction, $offset, $length);
    }
}
