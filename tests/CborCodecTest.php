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
 * Two things here are not visible in the corpus. Every mainnet transaction in the pool happens to use the shortest
 * head for every integer, so nothing on chain shows whether a wider one would survive; and no fixture carries a token
 * quantity above PHP's signed integer range, though the ledger's type allows one. Both are tested here directly,
 * because a decoder that quietly narrowed either would move a transaction hash.
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
}
