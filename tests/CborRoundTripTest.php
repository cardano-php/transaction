<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
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
 * The second half is the other property: a decoder handed bytes that are not CBOR refuses them. Anything else, from
 * a truncated structure handed back as if it were whole to an error that is not a DecodeException, is a failure.
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
            $bytes = self::document(0);

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
            $bytes = self::document(0);
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
            $bytes = self::corrupted(self::document(0));

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
     * The same documents through the transaction layer, which is where the hash is computed.
     *
     * The three cases above stop at CborCodec, and CborCodec is the layer with nothing in it that takes a
     * transaction apart: it holds the head of every item it reads and hands all of them back. The layer above it
     * reads fields out into a model and rebuilds them, and a document that the codec reproduces can still come back
     * from there as different bytes. Anything a generated document reaches has to hold at both layers, so the
     * decoder either refuses it by name or answers with exactly what it was given.
     *
     * Almost every document here is refused, because almost nothing shaped at random is a transaction. What the
     * count at the end says is that the case is wired to the transaction layer at all.
     */
    #[DataProvider('seeds')]
    public function test_a_generated_document_reaching_the_transaction_layer_is_refused_or_reproduced(int $seed): void
    {
        mt_srand($seed + 3000);

        $refused = 0;

        for ($index = 0; $index < self::DOCUMENTS; $index++) {
            $bytes = self::document(0);

            try {
                $transaction = TransactionDecoder::decode($bytes);
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

            $this->assertSame(bin2hex($bytes), bin2hex($transaction->encode()));
        }

        $this->assertGreaterThan(0, $refused, 'Nothing was refused, so this case is not reaching the decoder.');
    }

    // ------------------------------------------------------------------ the generator

    /**
     * One random CBOR document, written straight out as bytes so that what it should decode to is already known.
     */
    private static function document(int $depth): string
    {
        $leafOnly = $depth >= 4;
        $kind = mt_rand(0, $leafOnly ? 5 : 10);

        return match ($kind) {
            0 => self::head(0, mt_rand(0, 100000)),
            1 => self::head(1, mt_rand(0, 100000)),
            2 => self::definiteString(2),
            3 => self::definiteString(3),
            4 => self::simple(),
            5 => self::chunkedString(mt_rand(0, 1) === 0 ? 2 : 3),
            6 => self::sequence($depth, false),
            7 => self::sequence($depth, true),
            8 => self::map($depth, false),
            9 => self::map($depth, true),
            default => self::head(6, self::tagNumber()).self::document($depth + 1),
        };
    }

    /**
     * A head for $major carrying $argument, at a width chosen from the ones that hold it.
     *
     * The shortest is what every encoder in the ecosystem writes and the widest is what nothing writes, which is
     * exactly why both belong here.
     */
    private static function head(int $major, int $argument): string
    {
        $widths = [];

        if ($argument <= 23) {
            $widths[] = -1;
        }

        if ($argument <= 0xFF) {
            $widths[] = 1;
        }

        if ($argument <= 0xFFFF) {
            $widths[] = 2;
        }

        $widths[] = 4;
        $widths[] = 8;

        $width = $widths[mt_rand(0, count($widths) - 1)];

        return match ($width) {
            -1 => chr($major << 5 | $argument),
            1 => chr($major << 5 | 24).chr($argument),
            2 => chr($major << 5 | 25).pack('n', $argument),
            4 => chr($major << 5 | 26).pack('N', $argument),
            default => chr($major << 5 | 27).pack('J', $argument),
        };
    }

    private static function definiteString(int $major): string
    {
        $length = mt_rand(0, 12);
        $payload = '';

        for ($index = 0; $index < $length; $index++) {
            // Text strings stay inside printable ASCII, which is valid UTF-8 whatever bytes land next to it.
            $payload .= $major === 3 ? chr(mt_rand(0x20, 0x7E)) : chr(mt_rand(0, 255));
        }

        return self::head($major, $length).$payload;
    }

    private static function chunkedString(int $major): string
    {
        $bytes = chr($major << 5 | 31);

        for ($index = 0, $chunks = mt_rand(0, 3); $index < $chunks; $index++) {
            $bytes .= self::definiteString($major);
        }

        return $bytes."\xff";
    }

    private static function sequence(int $depth, bool $indefinite): string
    {
        $count = mt_rand(0, 4);
        $items = '';

        for ($index = 0; $index < $count; $index++) {
            $items .= self::document($depth + 1);
        }

        return $indefinite
            ? chr(4 << 5 | 31).$items."\xff"
            : self::head(4, $count).$items;
    }

    /**
     * A map whose keys are distinct integers, so the document is one a decoder is allowed to accept.
     */
    private static function map(int $depth, bool $indefinite): string
    {
        $count = mt_rand(0, 4);
        $entries = '';

        for ($index = 0; $index < $count; $index++) {
            $entries .= self::head(0, $index).self::document($depth + 1);
        }

        return $indefinite
            ? chr(5 << 5 | 31).$entries."\xff"
            : self::head(5, $count).$entries;
    }

    private static function simple(): string
    {
        return match (mt_rand(0, 6)) {
            0 => "\xf4",
            1 => "\xf5",
            2 => "\xf6",
            3 => "\xf7",
            4 => chr(7 << 5 | mt_rand(0, 19)),
            5 => "\xf9".pack('n', mt_rand(0, 0xFFFF)),
            default => "\xfa".pack('N', mt_rand(0, 0x7FFFFFFF)),
        };
    }

    private static function tagNumber(): int
    {
        return [0, 2, 18, 24, 121, 258, 1004, 100000][mt_rand(0, 7)];
    }

    /**
     * The same document with one byte changed, truncated or added to.
     */
    private static function corrupted(string $bytes): string
    {
        return match (mt_rand(0, 3)) {
            0 => $bytes.chr(mt_rand(0, 255)),
            1 => substr($bytes, 0, max(0, strlen($bytes) - mt_rand(1, 3))),
            2 => self::withByteChanged($bytes),
            default => substr($bytes, mt_rand(1, 2)),
        };
    }

    private static function withByteChanged(string $bytes): string
    {
        if ($bytes === '') {
            return "\x00";
        }

        $position = mt_rand(0, strlen($bytes) - 1);
        $bytes[$position] = chr(mt_rand(0, 255));

        return $bytes;
    }
}
