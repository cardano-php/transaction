<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use PHPUnit\Framework\TestCase;

/**
 * The order a multiasset map is written in, and what it must never hold twice.
 *
 * The ledger takes a multiasset map in any order, so none of this is about validity. It exists because a builder
 * that writes an arbitrary order cannot be compared byte for byte against one that writes the canonical order, and
 * because a hardware wallet asked to sign a transaction whose maps are out of canonical order refuses it. A
 * transaction this application signs with its own key does not care; a deposit transaction handed to a customer's
 * wallet to sign would.
 *
 * The ordering rule is the CBOR one, over the encoded key rather than the raw bytes, and the difference between
 * those two is the whole reason this file exists rather than a call to sort().
 */
class CanonicalOrderTest extends TestCase
{
    private const POLICY_A = 'c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f';

    private const POLICY_B = 'c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d5400';

    /**
     * Shorter names first, then by bytes, which is not the same as sorting the raw names.
     *
     * Declared here as `7a7a`, `00`, `4f4e424f4152` and the empty name. Sorting the raw bytes would put
     * `4f4e424f4152` before `7a7a`, because 0x4f is less than 0x7a. Sorting the encoded keys puts `7a7a` first,
     * because a two byte string encodes as 0x42 and a six byte one as 0x46. Both are defensible readings of "sort
     * the keys" and only one of them is what CBOR means.
     */
    public function test_names_are_ordered_by_their_encoded_length_first(): void
    {
        $bundle = AssetBundle::of(self::bytes(self::POLICY_A), [
            ["\x7a\x7a", '5'],
            ["\x00", '1'],
            [self::bytes('4f4e424f4152'), '99'],
            ['', '7'],
        ]);

        $canonical = $bundle->canonical();

        $this->assertSame(
            ['', '00', '7a7a', '4f4e424f4152'],
            array_map(static fn (array $asset): string => bin2hex($asset[0]), $canonical->assets())
        );

        $raw = array_map(static fn (array $asset): string => bin2hex($asset[0]), $bundle->assets());
        sort($raw);

        $this->assertNotSame(
            $raw,
            array_map(static fn (array $asset): string => bin2hex($asset[0]), $canonical->assets()),
            'Canonical order came out the same as sorting the raw names, so this case proves nothing.'
        );
    }

    /**
     * Policy ids are all 28 bytes, so for them the encoded rule and the raw rule agree.
     */
    public function test_policies_are_ordered_by_their_bytes(): void
    {
        $canonical = MultiAsset::canonical([
            AssetBundle::of(self::bytes(self::POLICY_A), [[self::bytes('4f4e45'), '1']]),
            AssetBundle::of(self::bytes(self::POLICY_B), [[self::bytes('54574f'), '2']]),
        ]);

        $this->assertSame(
            [self::POLICY_B, self::POLICY_A],
            array_map(static fn (AssetBundle $b): string => $b->policyIdHex(), $canonical->bundles())
        );
    }

    /**
     * Ordering changes the bytes and nothing else.
     */
    public function test_reordering_does_not_change_what_the_map_holds(): void
    {
        $bundles = [
            AssetBundle::of(self::bytes(self::POLICY_A), [[self::bytes('7a7a'), '5'], ['', '7']]),
            AssetBundle::of(self::bytes(self::POLICY_B), [[self::bytes('54574f'), '2']]),
        ];

        $declared = MultiAsset::of($bundles);
        $canonical = MultiAsset::canonical($bundles);

        $this->assertNotSame(
            bin2hex(CborCodec::encode($declared->toCbor())),
            bin2hex(CborCodec::encode($canonical->toCbor()))
        );

        $this->assertSame($declared->policyCount(), $canonical->policyCount());
        $this->assertSame($declared->assetCount(), $canonical->assetCount());
    }

    /**
     * A repeated policy is refused rather than merged.
     *
     * Two entries for one policy make a CBOR map with a repeated key, which is not a map. Adding the quantities
     * together would be a guess at what the caller meant and would produce a transaction moving a different amount
     * from the one they asked for.
     */
    public function test_a_repeated_policy_is_refused(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('appears twice');

        MultiAsset::canonical([
            AssetBundle::of(self::bytes(self::POLICY_A), [[self::bytes('4f4e45'), '1']]),
            AssetBundle::of(self::bytes(self::POLICY_A), [[self::bytes('54574f'), '2']]),
        ]);
    }

    /**
     * A repeated asset name under one policy is refused for the same reason.
     */
    public function test_a_repeated_asset_name_is_refused(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('The asset name 4f4e45 appears twice');

        AssetBundle::of(self::bytes(self::POLICY_A), [
            [self::bytes('4f4e45'), '1'],
            [self::bytes('4f4e45'), '2'],
        ])->canonical();
    }

    /**
     * The empty asset name is a name, and repeating it is still a repeat.
     */
    public function test_a_repeated_empty_asset_name_is_refused_and_named(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('The asset name (empty) appears twice');

        AssetBundle::of(self::bytes(self::POLICY_A), [['', '1'], ['', '2']])->canonical();
    }

    /**
     * A quantity at the ledger ceiling survives ordering, because ordering never touches a quantity.
     */
    public function test_a_uint64_quantity_survives_ordering(): void
    {
        $canonical = MultiAsset::canonical([
            AssetBundle::of(self::bytes(self::POLICY_A), [
                [self::bytes('7a7a'), '1'],
                [self::bytes('4d4158'), '18446744073709551615'],
            ]),
        ]);

        $assets = $canonical->bundles()[0]->assets();

        $this->assertSame('7a7a', bin2hex($assets[0][0]));
        $this->assertSame('18446744073709551615', $assets[1][1]->value);
    }

    private static function bytes(string $hex): string
    {
        return (string) hex2bin($hex);
    }
}
