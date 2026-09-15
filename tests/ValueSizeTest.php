<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\ValueSize;
use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\Value;
use PHPUnit\Framework\TestCase;

/**
 * maxValueSize is about bytes, and this file is about the difference that makes.
 *
 * Every case here is built so that a check written against an asset count gives the wrong answer. That is the
 * mistake the rule invites, and the reason the packer measures instead of counting.
 */
class ValueSizeTest extends TestCase
{
    private function parameters(): LedgerParameters
    {
        return LedgerFixtures::parameters();
    }

    private function policy(int $seed): string
    {
        return str_repeat(chr($seed % 256), 28);
    }

    /**
     * The same asset count, a factor of several in serialized size. A count-based rule cannot tell these apart, and
     * whichever threshold it picked would be wrong about one of them.
     */
    public function test_the_same_asset_count_can_differ_several_fold_in_size(): void
    {
        $cheap = Value::of('2000000', MultiAsset::of([
            AssetBundle::of($this->policy(1), $this->assets(60, nameLength: 0, quantity: '1')),
        ]));

        $expensive = Value::of('2000000', MultiAsset::of(array_map(
            fn (int $i): AssetBundle => AssetBundle::of(
                $this->policy($i),
                [[str_repeat('n', 32), Natural::UINT64_MAX]]
            ),
            range(1, 60)
        )));

        $this->assertSame(60, $cheap->assetCount());
        $this->assertSame(60, $expensive->assetCount());

        $this->assertGreaterThan(
            ValueSize::of($cheap) * 4,
            ValueSize::of($expensive),
            'Sixty assets under sixty policies with long names and full quantities should dwarf sixty short ones '
            .'under one policy.'
        );
    }

    /**
     * The limit is inclusive. A value of exactly maxValueSize bytes is one the ledger accepts, and the corpus
     * contains a real output at exactly that size, so a strict comparison here would refuse a real transaction.
     */
    public function test_a_value_of_exactly_max_value_size_fits(): void
    {
        $sizes = ValueSize::under($this->parameters());
        $limit = $this->parameters()->maxValueSize->toInt();

        $value = $this->grownTo($limit);

        $this->assertSame($limit, ValueSize::of($value));
        $this->assertTrue($sizes->fits($value), 'maxValueSize is a maximum, not a strict bound.');
    }

    public function test_a_value_one_byte_over_does_not_fit(): void
    {
        $sizes = ValueSize::under($this->parameters());
        $limit = $this->parameters()->maxValueSize->toInt();

        $value = $this->grownTo($limit + 1);

        $this->assertSame($limit + 1, ValueSize::of($value));
        $this->assertFalse($sizes->fits($value));
    }

    public function test_headroom_reaches_nought_and_does_not_go_negative(): void
    {
        $sizes = ValueSize::under($this->parameters());
        $limit = $this->parameters()->maxValueSize->toInt();

        $this->assertSame(0, $sizes->headroom($this->grownTo($limit)));
        $this->assertSame(0, $sizes->headroom($this->grownTo($limit + 1)));
        $this->assertGreaterThan(0, $sizes->headroom(Value::lovelace('2000000')));
        $this->assertSame(1, $sizes->headroom($this->grownTo($limit - 1)));
    }

    /**
     * A bare lovelace value is a CBOR integer and nothing else, so its size is a handful of bytes however much it
     * holds. Stated because a reader could reasonably expect the coin to be the expensive part.
     */
    public function test_a_lovelace_only_value_is_a_few_bytes_whatever_it_holds(): void
    {
        $this->assertLessThan(12, ValueSize::of(Value::lovelace('45000000000000000')));
    }

    /**
     * Grow a value to an exact byte length by adding assets and then trimming the last asset name.
     */
    private function grownTo(int $target): Value
    {
        $bundles = [];
        $index = 1;

        while (true) {
            $candidate = Value::of('2000000', MultiAsset::of([
                ...$bundles,
                AssetBundle::of($this->policy($index), [[str_repeat('n', 32), '1']]),
            ]));

            if (ValueSize::of($candidate) > $target) {
                break;
            }

            $bundles[] = AssetBundle::of($this->policy($index), [[str_repeat('n', 32), '1']]);
            $index++;
        }

        $value = Value::of('2000000', MultiAsset::of($bundles));
        $shortfall = $target - ValueSize::of($value);

        if ($shortfall === 0) {
            return $value;
        }

        // One more bundle whose asset name is sized to land on the target exactly. An asset name of n bytes costs
        // n plus one for the header up to 23 bytes, and the policy plus map framing is a constant on top.
        for ($nameLength = 0; $nameLength <= 32; $nameLength++) {
            $candidate = Value::of('2000000', MultiAsset::of([
                ...$bundles,
                AssetBundle::of($this->policy($index), [[str_repeat('n', $nameLength), '1']]),
            ]));

            if (ValueSize::of($candidate) === $target) {
                return $candidate;
            }
        }

        $this->fail(sprintf('Could not build a value of exactly %d bytes.', $target));
    }

    /**
     * @return list<array{string, string}>
     */
    private function assets(int $count, int $nameLength, string $quantity): array
    {
        $assets = [];
        for ($i = 0; $i < $count; $i++) {
            $assets[] = [$nameLength === 0 ? pack('n', $i) : str_repeat(chr(97 + $i % 26), $nameLength), $quantity];
        }

        return $assets;
    }
}
