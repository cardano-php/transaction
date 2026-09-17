<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
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
