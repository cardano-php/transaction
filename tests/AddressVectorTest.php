<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\CredentialKind;
use Cardano\Transaction\Address\EnterpriseAddress;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Address\Pointer;
use Cardano\Transaction\Address\PointerAddress;
use Cardano\Transaction\Address\RewardAddress;
use Cardano\Transaction\Exception\AddressException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The published CIP-19 test vectors, for all ten address types on both networks.
 *
 * These are the only values in this file that nothing in this repository can influence. The bech32 strings are copied
 * from the CIP; the headers, payloads and key hashes beside them in the fixture were produced by the BIP-173
 * reference bech32 implementation and Python's hashlib blake2b, not by the code being tested. See
 * tests/fixtures/cardano-addresses/README.md.
 *
 * Both directions are asserted for each vector, because they fail differently. Building the published string proves
 * the header byte, the field order and the encoder. Reading it back into credentials proves the parser agrees about
 * which bytes were which, which a builder alone cannot show.
 */
class AddressVectorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return JsonFixture::read('cardano-addresses/cip19-vectors.json');
    }

    public static function publishedVectors(): array
    {
        $cases = [];

        foreach (self::fixture()['addresses'] as $vector) {
            $cases[$vector['network'].' type '.$vector['address_type']] = [$vector];
        }

        return $cases;
    }

    /**
     * The credentials the CIP says every vector was built from.
     *
     * @return array{payment: Credential, script: Credential, stake: Credential, stakeScript: Credential, pointer: Pointer}
     */
    private static function parts(): array
    {
        $keys = self::fixture()['keys'];

        return [
            'payment' => Credential::keyHash($keys['payment_key_hash']),
            'script' => Credential::scriptHash($keys['script_hash']),
            'stake' => Credential::keyHash($keys['stake_key_hash']),
            'stakeScript' => Credential::scriptHash($keys['script_hash']),
            'pointer' => Pointer::at(
                $keys['pointer']['slot'],
                $keys['pointer']['tx_index'],
                $keys['pointer']['cert_index'],
            ),
        ];
    }

    private static function build(int $type, Network $network): Address
    {
        $p = self::parts();

        return match ($type) {
            0 => BaseAddress::of($network, $p['payment'], $p['stake']),
            1 => BaseAddress::of($network, $p['script'], $p['stake']),
            2 => BaseAddress::of($network, $p['payment'], $p['stakeScript']),
            3 => BaseAddress::of($network, $p['script'], $p['stakeScript']),
            4 => PointerAddress::of($network, $p['payment'], $p['pointer']),
            5 => PointerAddress::of($network, $p['script'], $p['pointer']),
            6 => EnterpriseAddress::of($network, $p['payment']),
            7 => EnterpriseAddress::of($network, $p['script']),
            14 => RewardAddress::of($network, $p['stake']),
            15 => RewardAddress::of($network, $p['stakeScript']),
        };
    }

    // --------------------------------------------------------- building them

    #[DataProvider('publishedVectors')]
    public function test_the_published_address_is_built_from_its_credentials(array $vector): void
    {
        $address = self::build($vector['address_type'], Network::named($vector['network'] === 'mainnet' ? 'mainnet' : 'preprod'));

        $this->assertSame($vector['bech32'], $address->toBech32());
    }

    #[DataProvider('publishedVectors')]
    public function test_the_published_bytes_are_built_from_its_credentials(array $vector): void
    {
        $address = self::build($vector['address_type'], Network::named($vector['network'] === 'mainnet' ? 'mainnet' : 'preprod'));

        $this->assertSame($vector['bytes'], $address->toHex());
        $this->assertSame($vector['header'], sprintf('%02x', $address->header()));
        $this->assertSame($vector['payload'], bin2hex($address->payload()));
    }

    // ---------------------------------------------------------- reading them

    #[DataProvider('publishedVectors')]
    public function test_the_published_address_reads_back_to_the_bytes_an_independent_decoder_found(array $vector): void
    {
        $address = Address::fromBech32($vector['bech32']);

        $this->assertSame($vector['bytes'], $address->toHex());
        $this->assertSame($vector['address_type'], $address->type());
        $this->assertSame($vector['network'] === 'mainnet' ? 1 : 0, $address->network->value);
        $this->assertSame($vector['hrp'], $address->hrp());
    }

    #[DataProvider('publishedVectors')]
    public function test_the_published_address_survives_a_round_trip_through_bytes_and_bech32(array $vector): void
    {
        $fromBech32 = Address::fromBech32($vector['bech32']);
        $fromBytes = Address::fromHex($vector['bytes']);

        $this->assertSame($vector['bech32'], $fromBech32->toBech32());
        $this->assertSame($vector['bech32'], $fromBytes->toBech32());
        $this->assertSame($fromBech32->toHex(), $fromBytes->toHex());
    }

    #[DataProvider('publishedVectors')]
    public function test_the_published_address_reads_back_into_the_credentials_it_was_built_from(array $vector): void
    {
        $keys = self::fixture()['keys'];
        $address = Address::fromBech32($vector['bech32']);
        $type = $vector['address_type'];

        // Even types below 6 pay to a key, odd ones to a script. Reward addresses invert nothing: 14 is a key, 15 a
        // script. Getting this bit wrong produces an address that checksums perfectly and nobody can spend.
        $expected = match ($type) {
            0, 2, 4, 6, 14 => [CredentialKind::Key, $type === 14 ? $keys['stake_key_hash'] : $keys['payment_key_hash']],
            default => [CredentialKind::Script, $keys['script_hash']],
        };

        $this->assertSame($expected[0], $address->credential()->kind);
        $this->assertSame($expected[1], $address->credential()->hex());
    }

    public function test_a_base_address_reads_back_both_of_its_credentials(): void
    {
        $keys = self::fixture()['keys'];
        $address = Address::fromBech32(self::vector('mainnet', 2)['bech32']);

        $this->assertInstanceOf(BaseAddress::class, $address);
        $this->assertSame($keys['payment_key_hash'], $address->payment->hex());
        $this->assertSame(CredentialKind::Key, $address->payment->kind);
        $this->assertSame($keys['script_hash'], $address->delegation->hex());
        $this->assertSame(CredentialKind::Script, $address->delegation->kind);
    }

    public function test_a_pointer_address_reads_back_its_three_coordinates(): void
    {
        $keys = self::fixture()['keys'];
        $address = Address::fromBech32(self::vector('mainnet', 4)['bech32']);

        $this->assertInstanceOf(PointerAddress::class, $address);
        $this->assertSame($keys['pointer']['slot'], $address->pointer->slot);
        $this->assertSame($keys['pointer']['tx_index'], $address->pointer->txIndex);
        $this->assertSame($keys['pointer']['cert_index'], $address->pointer->certIndex);
    }

    /**
     * The one part of CIP-19 with an encoding of its own. 2498243 does not fit in seven bits, so it is written as
     * four bytes with the continuation bit set on the first three, and the published vector is the only thing that
     * says whether that was done the right way round.
     */
    public function test_the_published_pointer_encodes_to_its_published_bytes(): void
    {
        $keys = self::fixture()['keys'];
        $pointer = Pointer::at(2498243, 27, 3);

        $this->assertSame('8198bd431b03', bin2hex($pointer->toBytes()));
        $this->assertSame(
            $keys['payment_key_hash'].'8198bd431b03',
            self::vector('mainnet', 4)['payload'],
        );
    }

    public function test_a_pointer_survives_a_round_trip_at_every_group_boundary(): void
    {
        foreach ([0, 1, 127, 128, 16383, 16384, 2097151, 2097152, 2498243, PHP_INT_MAX >> 7] as $value) {
            $pointer = Pointer::at($value, 27, 3);

            $this->assertSame(
                [$value, 27, 3],
                array_values(Pointer::fromBytes($pointer->toBytes())->toArray()),
                'A pointer slot of '.$value.' does not survive a round trip.'
            );
        }
    }

    // ------------------------------------------------ the network tag matters

    public function test_the_same_credentials_give_different_addresses_on_the_two_networks(): void
    {
        $mainnet = self::vector('mainnet', 7)['bech32'];
        $testnet = self::vector('testnet', 7)['bech32'];

        $this->assertNotSame($mainnet, $testnet);
        $this->assertSame(
            Address::fromBech32($mainnet)->credential()->hex(),
            Address::fromBech32($testnet)->credential()->hex(),
        );
    }

    public function test_preprod_and_preview_share_a_network_tag(): void
    {
        $this->assertSame(Network::named('preprod'), Network::named('preview'));
        $this->assertSame(0, Network::named('preprod')->value);
        $this->assertSame(1, Network::named('mainnet')->value);
    }

    public function test_an_unknown_network_name_is_refused(): void
    {
        $this->expectException(AddressException::class);

        Network::named('sanchonet');
    }

    public function test_a_reserved_network_tag_is_refused(): void
    {
        $this->expectException(AddressException::class);

        // Header 0x62: an enterprise key address on network 2, which CIP-19 reserves.
        Address::fromHex('62'.self::fixture()['keys']['payment_key_hash']);
    }

    // ------------------------------------------------------------- refusals

    public function test_a_prefix_that_disagrees_with_the_header_is_refused(): void
    {
        // The bytes of a mainnet enterprise address, re-labelled as a test network address. The checksum is valid;
        // only the header says otherwise.
        $bytes = self::vector('mainnet', 7)['bytes'];
        $relabelled = self::encodeWith('addr_test', $bytes);

        $this->expectException(AddressException::class);

        Address::fromBech32($relabelled);
    }

    public function test_a_reward_address_labelled_as_a_payment_address_is_refused(): void
    {
        $relabelled = self::encodeWith('addr', self::vector('mainnet', 14)['bytes']);

        $this->expectException(AddressException::class);

        Address::fromBech32($relabelled);
    }

    public function test_a_byron_address_is_named_rather_than_misread(): void
    {
        $this->expectException(AddressException::class);
        $this->expectExceptionMessage('Byron');

        // A Byron address begins with a CBOR array head, 0x82, whose high nibble is the type 8 CIP-19 assigns to it.
        Address::fromHex('82d818584283581c'.str_repeat('ab', 20));
    }

    public function test_an_unassigned_address_type_is_refused(): void
    {
        $this->expectException(AddressException::class);

        // Type 9, which CIP-19 leaves unassigned.
        Address::fromHex('91'.self::fixture()['keys']['payment_key_hash']);
    }

    public static function truncationsAndPaddings(): array
    {
        return [
            'an enterprise address one byte short' => ['61'.str_repeat('ab', 27)],
            'an enterprise address one byte long' => ['61'.str_repeat('ab', 29)],
            'a base address missing its delegation part' => ['01'.str_repeat('ab', 28)],
            'a base address one byte short' => ['01'.str_repeat('ab', 55)],
            'a reward address one byte long' => ['e1'.str_repeat('ab', 29)],
            'a pointer address with no pointer' => ['41'.str_repeat('ab', 28)],
            'a pointer address with two coordinates' => ['41'.str_repeat('ab', 28).'1b03'],
            'a pointer address with a fourth coordinate' => ['41'.str_repeat('ab', 28).'8198bd431b0304'],
            'a pointer whose last natural never ends' => ['41'.str_repeat('ab', 28).'1b0383'],
            'a header and nothing else' => ['61'],
            'nothing at all' => [''],
        ];
    }

    #[DataProvider('truncationsAndPaddings')]
    public function test_an_address_of_the_wrong_length_is_refused(string $hex): void
    {
        $this->expectException(AddressException::class);

        Address::fromHex($hex);
    }

    public function test_an_uppercase_address_is_refused_rather_than_quietly_lowercased(): void
    {
        $this->expectException(AddressException::class);

        Address::fromBech32(strtoupper(self::vector('mainnet', 6)['bech32']));
    }

    public function test_an_address_with_a_broken_checksum_is_refused(): void
    {
        $published = self::vector('mainnet', 6)['bech32'];
        $broken = substr($published, 0, -1).($published[strlen($published) - 1] === 'l' ? '7' : 'l');

        $this->expectException(AddressException::class);

        Address::fromBech32($broken);
    }

    public function test_a_credential_of_the_wrong_length_or_spelling_is_refused(): void
    {
        foreach ([
            '',
            str_repeat('ab', 27),
            str_repeat('ab', 29),
            strtoupper(str_repeat('ab', 28)),
            '0x'.str_repeat('ab', 27),
            ' '.str_repeat('ab', 28).' ',
            str_repeat('zz', 28),
        ] as $hex) {
            try {
                Credential::keyHash($hex);
                $this->fail('A key hash spelled "'.$hex.'" was accepted.');
            } catch (AddressException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------ derivations

    /**
     * CIP-19 publishes the verification keys rather than their hashes, and says a key hash is blake2b-224 over the
     * key. Hashing the published key has to give the hash the published address carries.
     */
    public function test_a_credential_from_a_verification_key_matches_the_published_address(): void
    {
        $keys = self::fixture()['keys'];
        $credential = Credential::fromVerificationKey((string) hex2bin($keys['payment_verification_key_hex']));

        $this->assertSame($keys['payment_key_hash'], $credential->hex());
        $this->assertSame(
            self::vector('mainnet', 6)['bech32'],
            EnterpriseAddress::of(Network::Mainnet, $credential)->toBech32(),
        );
    }

    public function test_a_verification_key_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(AddressException::class);

        Credential::fromVerificationKey(str_repeat("\x00", 31));
    }

    /**
     * A base address carries the stake credential a reward address carries, so one can be read off the other. The two
     * published vectors say whether that is true byte for byte.
     */
    public function test_the_reward_address_of_a_base_address_is_the_published_reward_address(): void
    {
        $base = Address::fromBech32(self::vector('mainnet', 0)['bech32']);

        $this->assertInstanceOf(BaseAddress::class, $base);
        $this->assertSame(self::vector('mainnet', 14)['bech32'], $base->rewardAddress()->toBech32());
        $this->assertSame(self::vector('mainnet', 6)['bech32'], $base->enterpriseAddress()->toBech32());
    }

    public function test_adding_a_delegation_credential_gives_the_published_base_address(): void
    {
        $enterprise = Address::fromBech32(self::vector('mainnet', 6)['bech32']);
        $reward = Address::fromBech32(self::vector('mainnet', 14)['bech32']);

        $this->assertInstanceOf(EnterpriseAddress::class, $enterprise);
        $this->assertInstanceOf(RewardAddress::class, $reward);
        $this->assertSame(
            self::vector('mainnet', 0)['bech32'],
            $enterprise->withDelegation($reward->stake)->toBech32(),
        );
    }

    // ------------------------------------------------------------------ detail

    private static function vector(string $network, int $type): array
    {
        foreach (self::fixture()['addresses'] as $vector) {
            if ($vector['network'] === $network && $vector['address_type'] === $type) {
                return $vector;
            }
        }

        self::fail(sprintf('The fixture has no %s vector for type %d.', $network, $type));
    }

    /**
     * Re-encode published bytes under a prefix that does not belong to them, to build an address that is valid
     * bech32 and a lie about itself.
     */
    private static function encodeWith(string $hrp, string $hex): string
    {
        return \CardanoPhp\Bech32\Bech32::encode($hrp, \CardanoPhp\Bech32\Bech32::hexToByteArray($hex));
    }
}
