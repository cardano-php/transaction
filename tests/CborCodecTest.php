<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Brick\Math\BigInteger;
use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Exception\DecodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The CBOR layer on its own, away from any transaction.
 *
 * Several things here are not visible in the corpus. Every mainnet transaction in the pool happens to use the
 * shortest head for every integer, every length and every count, so nothing on chain shows whether a wider one would
 * survive; and no fixture carries a token quantity above PHP's signed integer range, though the ledger's type allows
 * one. A decoder that quietly narrowed any of it would move a transaction hash, and only these say otherwise.
 *
 * The rest is the refusals. The corpus is transactions the ledger accepted, so it cannot show what a decoder does
 * with a document nobody would write on purpose: a break byte with nothing open, a map key with no value after it,
 * an indefinite length string holding something that is not a string, one key written twice in two widths.
 */
class CborCodecTest extends TestCase
{
    public static function integerEncodings(): array
    {
        return [
            'zero, immediate' => ['00', '0'],
            'twenty three, immediate' => ['17', '23'],
            'twenty four, one byte' => ['1818', '24'],
            'one, written in one byte' => ['1801', '1'],
            'one, written in two bytes' => ['190001', '1'],
            'one, written in four bytes' => ['1a00000001', '1'],
            'one, written in eight bytes' => ['1b0000000000000001', '1'],
            'a fee' => ['1a000aae60', '700000'],
            'the largest uint64' => ['1bffffffffffffffff', '18446744073709551615'],
            'minus one, immediate' => ['20', '-1'],
            'minus one thousand' => ['3903e7', '-1000'],
            'minus one, written in eight bytes' => ['3b0000000000000000', '-1'],
        ];
    }

    #[DataProvider('integerEncodings')]
    public function test_an_integer_keeps_the_width_it_arrived_in(string $hex, string $value): void
    {
        $integer = CborInteger::fromCbor(CborCodec::decode(hex2bin($hex)), 'test');

        $this->assertSame($value, $integer->value);
        $this->assertSame($hex, bin2hex(CborCodec::encode($integer->toCbor())));
    }

    public function test_a_quantity_above_the_php_integer_range_is_readable_as_a_string(): void
    {
        $integer = CborInteger::fromCbor(CborCodec::decode(hex2bin('1bffffffffffffffff')), 'test');

        $this->assertSame('18446744073709551615', $integer->value);

        $this->expectException(DecodeException::class);
        $integer->toInt();
    }

    public function test_a_negative_integer_is_refused_where_the_ledger_writes_an_unsigned_one(): void
    {
        $this->expectException(DecodeException::class);

        CborInteger::unsignedFromCbor(CborCodec::decode(hex2bin('20')), 'test');
    }

    public function test_a_byte_string_is_not_an_integer(): void
    {
        $this->expectException(DecodeException::class);

        CborInteger::fromCbor(CborCodec::decode(hex2bin('4100')), 'test');
    }

    public function test_bytes_after_the_first_item_are_refused(): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin('0000'));
    }

    public function test_a_truncated_item_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin('1a0000'));
    }

    public function test_empty_input_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode('');
    }

    public function test_a_reserved_additional_information_value_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin('1c'));
    }

    public function test_an_indefinite_array_that_is_never_closed_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin('9f0102'));
    }

    public function test_a_repeated_map_key_is_refused(): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin('a200000000'));
    }

    /**
     * Every way CBOR has of writing the same thing, written back the way it arrived.
     *
     * The ledger hashes the bytes it was handed. A decoder that read `98 00` as an empty array and wrote `80` would
     * be right about the value and wrong about the transaction, and the error would show up as a hash nobody could
     * account for rather than as a refusal.
     */
    public static function encodingsThatSurviveExactly(): array
    {
        return [
            'an empty array' => ['80'],
            'an empty array with a one byte count' => ['9800'],
            'an empty array with a two byte count' => ['990000'],
            'an array of three' => ['83010203'],
            'an array of three with a four byte count' => ['9a00000003010203'],
            'an indefinite array' => ['9f010203ff'],
            'an indefinite array holding nothing' => ['9fff'],
            'a nested array' => ['818181818100'],
            'an empty map' => ['a0'],
            'a map with a one byte count' => ['b8010001'],
            'an indefinite map' => ['bf0001ff'],
            'a byte string' => ['4401020304'],
            'a byte string with a one byte length' => ['58020102'],
            'a byte string with an eight byte length' => ['5b00000000000000020102'],
            'an indefinite byte string' => ['5f42010243030405ff'],
            'an indefinite byte string holding nothing' => ['5fff'],
            'a text string' => ['63616263'],
            'an indefinite text string' => ['7f6161626263ff'],
            'a tag' => ['c001'],
            'tag 258 around a set' => ['d9010283010203'],
            'tag 258 written wider than it needs' => ['db000000000000010280'],
            'tag 24 in one byte' => ['d81843010203'],
            'false, true, null and undefined' => ['84f4f5f6f7'],
            'a simple value' => ['f0'],
            'a simple value with a one byte argument' => ['f820'],
            'a half precision float' => ['f93c00'],
            'a single precision float' => ['fa47c35000'],
            'a double precision float' => ['fb3ff199999999999a'],
            'a map keyed by every kind of thing' => ['a50000617800810001a0000101'],
            'a transaction shaped nest' => ['84a3008182582000000000000000000000000000000000000000000000000000000000000000000001800219030aa0f5f6'],
        ];
    }

    #[DataProvider('encodingsThatSurviveExactly')]
    public function test_an_encoding_is_written_back_the_way_it_arrived(string $hex): void
    {
        $bytes = hex2bin($hex);

        $this->assertSame($hex, bin2hex(CborCodec::encode(CborCodec::decode($bytes))));
    }

    /**
     * Two keys that are the same number written two ways are one key, and a map cannot hold it twice.
     *
     * RFC 8949 section 5.6 asks for that refusal, and it has to be about the value rather than about the bytes: a
     * decoder comparing encoded keys would read `{0: x, 0: y}` as two entries whenever the second 0 was written a
     * byte wider, and hand back a map with one of the two values silently chosen.
     */
    public static function mapsThatRepeatAKey(): array
    {
        return [
            'the same integer key twice' => ['a200000000'],
            'the same integer key at two widths' => ['a20000180000'],
            'the same integer key at four widths' => ['a4'.'1a00000000'.'00'.'190000'.'00'.'1800'.'00'.'00'.'00'],
            'the same negative key twice' => ['a220002001'],
            'the same byte string key twice' => ['a2410100410102'],
            'the same byte string key in two framings' => ['a24101005f4101ff01'],
            'the same text string key twice' => ['a2616100616101'],
            'the same array key twice' => ['a2810000810001'],
            'the same map key twice' => ['a2a000a001'],
            'the same nested map key twice' => ['a2'.'a100a10000'.'00'.'a100a10000'.'01'],
            'the same tagged key twice' => ['a2'.'c24101'.'00'.'c24101'.'01'],
            'the same simple value key twice' => ['a2f400f401'],
        ];
    }

    #[DataProvider('mapsThatRepeatAKey')]
    public function test_a_map_that_writes_one_key_twice_is_refused(string $hex): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin($hex));
    }

    /**
     * And the other direction, so the check above is about repeated keys rather than about widths at all.
     */
    public function test_distinct_keys_written_at_different_widths_are_distinct(): void
    {
        $hex = 'a3'.'00'.'00'.'190001'.'01'.'1a00000002'.'02';

        $this->assertSame($hex, bin2hex(CborCodec::encode(CborCodec::decode(hex2bin($hex)))));
    }

    /**
     * A key that is a container is compared by its bytes, so one written wider is a different key.
     *
     * This is the rule that was always here and it has not moved; what has moved is how the bytes are reached. They
     * are the slice of the input the key was read from rather than a re-encoding of the key, and the two have to
     * agree on both halves of the question: `8100` and `980100` are one item array holding nought written two ways,
     * and a map may carry both because anything hashing that map would see two different keys.
     */
    public function test_a_container_key_written_at_two_widths_is_two_keys(): void
    {
        $hex = 'a2'.'8100'.'00'.'980100'.'01';

        $this->assertSame($hex, bin2hex(CborCodec::encode(CborCodec::decode(hex2bin($hex)))));
    }

    /**
     * What a document nesting maps as keys costs to decide, which used to be the length of the input squared.
     *
     * Naming a container key meant re-encoding it, so a key nested d deep was re-encoded once at every level above
     * it. The input below is one byte under maxTxSize, which makes it a document that fits in a transaction and
     * reaches this through the public decoder. It took over twenty seconds to accept, and a hostile one of the same
     * shape could not be refused cheaply either, which is the half of it that matters: a decoder that is expensive
     * to say no to is a decoder anyone can point at a service.
     *
     * The bound is loose on purpose. It is not a benchmark and it is not measuring a machine; it is far enough
     * below what the old cost was, and far enough above what the new one is, that only a return to walking the key
     * at every level can cross it.
     */
    public function test_a_transaction_sized_document_of_nested_map_keys_is_decided_promptly(): void
    {
        $depth = 8191;
        $bytes = str_repeat("\xa1", $depth)."\x00".str_repeat("\x00", $depth);

        $this->assertSame(16383, strlen($bytes), 'The input is meant to be one byte under maxTxSize.');

        $started = microtime(true);
        $value = CborCodec::decode($bytes);
        $elapsed = microtime(true) - $started;

        $this->assertSame(bin2hex($bytes), bin2hex(CborCodec::encode($value)));
        $this->assertLessThan(
            5.0,
            $elapsed,
            sprintf('A %d byte document of nested map keys took %.2f seconds to decode.', strlen($bytes), $elapsed)
        );
    }

    /**
     * The whole of the two byte simple value range that is not well formed, and the first one that is.
     *
     * RFC 8949 section 3.3 gives major type 7 with additional information 24 the simple values 32 to 255 and says a
     * two byte sequence continuing with a byte below 32 is not well formed. The thirty-two values below that have a
     * one byte head of their own, and four of them are false, true, null and undefined, so taking the two byte
     * spelling would mean holding a second encoding of a value that already has one: `f814` would come back as a
     * boolean that re-encodes to either two bytes or one, and a transaction carrying one would hash to whichever
     * the encoder picked.
     */
    public function test_every_two_byte_simple_value_below_thirty_two_is_refused(): void
    {
        for ($value = 0; $value < 32; $value++) {
            $bytes = "\xf8".chr($value);
            $thrown = null;

            try {
                CborCodec::decode($bytes);
            } catch (DecodeException $e) {
                $thrown = $e;
            }

            $this->assertInstanceOf(
                DecodeException::class,
                $thrown,
                sprintf('f8%02x was accepted; RFC 8949 section 3.3 says it is not well formed.', $value)
            );
        }

        for ($value = 32; $value < 256; $value++) {
            $bytes = "\xf8".chr($value);

            $this->assertSame(
                bin2hex($bytes),
                bin2hex(CborCodec::encode(CborCodec::decode($bytes))),
                sprintf('f8%02x is a simple value this decoder should read.', $value)
            );
        }
    }

    /**
     * How long an input this reads, and what happens to a longer one.
     *
     * The depth limit bounds one of the two ways a document costs memory. The other is breadth, which it says
     * nothing about: an array of a million items nests one level and is a million objects. Past some length the
     * answer stops being a refusal and becomes the allocator giving up, and that is a fatal error rather than an
     * exception, so a caller cannot catch it, log it or carry on serving. The length is checked before anything is
     * read, so a document far past the limit costs the same to refuse as one a byte past it.
     */
    public function test_an_input_longer_than_the_limit_is_refused_before_it_is_read(): void
    {
        $length = CborCodec::MAX_INPUT_BYTES;
        $payload = $length - 5;
        $atTheLimit = "\x5a".pack('N', $payload).str_repeat('A', $payload);

        $this->assertSame($length, strlen($atTheLimit));
        $this->assertSame(
            bin2hex($atTheLimit),
            bin2hex(CborCodec::encode(CborCodec::decode($atTheLimit))),
            'The longest input the limit allows is not read and written back whole.'
        );

        foreach ([1, 1024, 1024 * 1024] as $over) {
            $bytes = $atTheLimit.str_repeat('A', $over);
            $thrown = null;

            try {
                CborCodec::decode($bytes);
            } catch (DecodeException $e) {
                $thrown = $e;
            }

            $this->assertInstanceOf(
                DecodeException::class,
                $thrown,
                strlen($bytes).' bytes raised nothing at all.'
            );
            $this->assertStringContainsString((string) CborCodec::MAX_INPUT_BYTES, $thrown->getMessage());
        }
    }

    /**
     * And the refusal arrives without the document being walked, whatever shape it is.
     *
     * A document of forty million one byte array heads is what the depth limit alone leaves open: it is refused for
     * nesting, but only after forty million heads have been read and sixteen thousand values built. Checking the
     * length first turns that into a string comparison.
     */
    public function test_a_document_far_past_the_limit_is_refused_promptly(): void
    {
        $bytes = str_repeat("\x81", 40 * 1000 * 1000);

        $started = microtime(true);
        $thrown = null;

        try {
            CborCodec::decode($bytes);
        } catch (DecodeException $e) {
            $thrown = $e;
        }

        $elapsed = microtime(true) - $started;

        $this->assertInstanceOf(DecodeException::class, $thrown);
        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf('A %d byte document took %.2f seconds to refuse.', strlen($bytes), $elapsed)
        );
    }

    /**
     * Every head argument reads back as the number it holds, at every width and across every boundary.
     *
     * The argument is built with native shifts because every integer in a transaction comes through here, and
     * brick/math is reached for only where native arithmetic stops being an answer. Where that is depends on the
     * word size of the build, so the answer is checked against arbitrary precision arithmetic rather than against a
     * list, across the values on either side of each width boundary and on either side of PHP_INT_MAX.
     */
    public function test_a_head_argument_reads_back_as_the_number_it_holds(): void
    {
        $arguments = ['0', '1', '23', '24', '255', '256', '65535', '65536', '4294967295', '4294967296'];

        foreach ([PHP_INT_MAX, '9223372036854775808', '18446744073709551615'] as $wide) {
            $arguments[] = (string) $wide;
        }

        foreach ($arguments as $argument) {
            $number = BigInteger::of($argument);

            foreach (self::headsHolding($number) as $hex) {
                $value = CborCodec::decode(hex2bin($hex));

                $this->assertSame(
                    (string) $number,
                    $value->integerText(),
                    $hex.' does not read back as '.$number.'.'
                );
                $this->assertSame(
                    (string) BigInteger::of(-1)->minus($number),
                    CborCodec::decode(hex2bin(self::asNegative($hex)))->integerText(),
                    $hex.' does not read back as a negative integer either.'
                );
                $this->assertSame($hex, bin2hex(CborCodec::encode($value)));
            }
        }
    }

    /**
     * A freshly built value takes the narrowest head that holds its argument, whichever way the argument arrived.
     *
     * Two things reach CborHead::headFor: a PHP integer, which is what a length or a count is, and a BigInteger,
     * which is what an argument that may outrun the word size arrives as. Both have to choose the same width for
     * the same number, because the width is a fact about CBOR and not about the machine, and because the bytes are
     * what a script hash and a transaction hash are taken over.
     *
     * The branch that took a BigInteger used to decide by asking whether the number fitted a PHP integer. On a
     * 64-bit build that gives the right answer by coincidence, because everything that does not fit needs eight
     * bytes anyway. On a 32-bit build every argument from 2^31 to 2^32-1 is above PHP_INT_MAX and inside the four
     * byte width, so each one would have been written at an eight byte head: the same script built on two builds
     * would have hashed to two different things. This build cannot show that failure, and what it can do is hold
     * the widths to the table CBOR gives rather than to anything about the word size.
     */
    public function test_a_built_head_takes_the_narrowest_width_that_holds_its_argument(): void
    {
        $widths = [
            '0' => 0, '1' => 0, '23' => 0, '24' => 1, '255' => 1,
            '256' => 2, '65535' => 2, '65536' => 4, '2147483647' => 4, '2147483648' => 4,
            '4294967295' => 4, '4294967296' => 8, '9223372036854775807' => 8,
            '9223372036854775808' => 8, '18446744073709551615' => 8,
        ];

        foreach ($widths as $argument => $width) {
            $number = BigInteger::of((string) $argument);

            $bytes = CborCodec::encode(CborValue::unsigned((string) $argument));

            $this->assertSame(
                $width + 1,
                strlen($bytes),
                sprintf('The argument %s was written at a head of %d bytes rather than %d.', $argument, strlen($bytes) - 1, $width + 1)
            );

            $this->assertSame((string) $number, CborCodec::decode($bytes)->integerText());

            // The head a byte string of that many bytes would be written with is the same head, so a length and a
            // count choose the same widths a bare argument does.
            if ($number->isLessThanOrEqualTo(4096)) {
                $string = CborCodec::encode(CborValue::byteString(str_repeat("\x00", (int) $argument)));

                $this->assertSame(
                    $width + 1 + (int) $argument,
                    strlen($string),
                    sprintf('A byte string of %s bytes was written at a different head width.', $argument)
                );
            }
        }
    }

    /**
     * An argument past what a CBOR head can carry is refused rather than written at a head that cannot hold it.
     */
    public function test_an_argument_past_what_a_head_can_carry_is_refused(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('18446744073709551615');

        CborValue::unsigned('18446744073709551616');
    }

    /**
     * Every unsigned head that can state $number, from the narrowest to the widest.
     *
     * @return list<string>
     */
    private static function headsHolding(BigInteger $number): array
    {
        $heads = [];

        if ($number->isLessThanOrEqualTo(23)) {
            $heads[] = sprintf('%02x', $number->toInt());
        }

        foreach ([24 => 1, 25 => 2, 26 => 4, 27 => 8] as $additionalInformation => $width) {
            if ($number->isGreaterThan(BigInteger::of(256)->power($width)->minus(1))) {
                continue;
            }

            $heads[] = sprintf('%02x', $additionalInformation).str_pad($number->toBase(16), $width * 2, '0', STR_PAD_LEFT);
        }

        return $heads;
    }

    /** The same head written as a major type 1 item, which is the same argument meaning -1 minus itself. */
    private static function asNegative(string $hex): string
    {
        return sprintf('%02x', hexdec(substr($hex, 0, 2)) + 0x20).substr($hex, 2);
    }

    /**
     * Documents that are not CBOR, each refused for its own reason rather than read part way.
     */
    public static function documentsThatAreRefused(): array
    {
        return [
            'a break byte with nothing open' => ['ff'],
            'a break byte inside an array that stated its length' => ['81ff'],
            'an indefinite map with a key and no value' => ['bf00ff'],
            'an indefinite array that is never closed' => ['9f0102'],
            'an indefinite byte string holding an integer' => ['5f0102ff'],
            'an indefinite byte string holding a text string' => ['5f6161ff'],
            'an indefinite text string holding a byte string' => ['7f4101ff'],
            'an indefinite byte string holding another one' => ['5f5f41014101ffff'],
            'an integer written as indefinite in length' => ['1f'],
            'a negative integer written as indefinite in length' => ['3f'],
            'a tag written as indefinite in length' => ['df00'],
            'a tag with nothing after it' => ['c0'],
            'an array shorter than it says it is' => ['830102'],
            'a map shorter than it says it is' => ['a20001'],
            'a byte string shorter than it says it is' => ['4401'],
            'a reserved additional information value' => ['1d'],
            'the two byte form of the simple value 0' => ['f800'],
            'the two byte form of false' => ['f814'],
            'the two byte form of true' => ['f815'],
            'the two byte form of null' => ['f816'],
            'the two byte form of undefined' => ['f817'],
            'the two byte form of the last one byte simple value' => ['f81f'],
            'bytes after a nested item' => ['810000'],
        ];
    }

    #[DataProvider('documentsThatAreRefused')]
    public function test_a_malformed_document_is_refused(string $hex): void
    {
        $this->expectException(DecodeException::class);

        CborCodec::decode(hex2bin($hex));
    }
}
