<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\CredentialKind;
use Cardano\Transaction\Address\EnterpriseAddress;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Codec\TransactionDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every address the committed transaction corpus carries, read and written back.
 *
 * The published CIP-19 vectors are twenty addresses built from four credentials. These are addresses that real people
 * were paid at, in transactions the ledger accepted, and the decoder that produced the bytes has nothing to do with
 * the code reading them here. What they can show that the vectors cannot is that nothing in the wild is shaped in a
 * way this parser does not expect.
 */
class AddressCorpusTest extends TestCase
{
    /**
     * Each distinct address in the corpus, with the fixture it came from.
     */
    public static function corpusAddresses(): array
    {
        $cases = [];

        foreach (TransactionFixtures::chainFixtures() as $case) {
            $fixture = $case[0];
            $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

            $outputs = $transaction->body->outputs();
            $collateralReturn = $transaction->body->collateralReturn();

            if ($collateralReturn !== null) {
                $outputs[] = $collateralReturn;
            }

            foreach ($outputs as $index => $output) {
                $key = $fixture['id'].' output '.$index;
                $cases[$key] = [$output->addressHex(), $key];
            }
        }

        return $cases;
    }

    #[DataProvider('corpusAddresses')]
    public function test_an_address_from_the_corpus_re_encodes_to_the_bytes_it_was_read_from(string $hex, string $where): void
    {
        $this->assertSame($hex, Address::fromHex($hex)->toHex(), $where.' does not survive a round trip.');
    }

    #[DataProvider('corpusAddresses')]
    public function test_an_address_from_the_corpus_survives_a_round_trip_through_bech32(string $hex, string $where): void
    {
        $bech32 = Address::fromHex($hex)->toBech32();

        $this->assertSame($hex, Address::fromBech32($bech32)->toHex(), $where.' does not survive bech32.');
        $this->assertSame($bech32, Address::fromBech32($bech32)->toBech32());
    }

    /**
     * Every one of them is a mainnet address, which is the one fact about the corpus that is known from outside it:
     * the manifest says the transactions were fetched from a mainnet instance.
     */
    #[DataProvider('corpusAddresses')]
    public function test_an_address_from_the_corpus_is_a_mainnet_address(string $hex, string $where): void
    {
        $address = Address::fromHex($hex);

        $this->assertSame(Network::Mainnet, $address->network, $where.' is not on mainnet.');
        $this->assertSame('addr', $address->hrp());
        $this->assertStringStartsWith('addr1', $address->toBech32());
    }

    /**
     * The corpus was assembled around transaction shapes, not address shapes, so what it happens to contain is worth
     * stating rather than assuming. If a later fixture brings a pointer address or a Byron output, this fails and
     * says so instead of quietly widening what the round trip covers.
     */
    public function test_the_corpus_carries_the_address_types_this_test_claims_to_cover(): void
    {
        $types = [];

        foreach (self::corpusAddresses() as [$hex, $where]) {
            $types[Address::fromHex($hex)->type()] = true;
        }

        ksort($types);

        $this->assertSame([0, 6, 7], array_keys($types));
        $this->assertCount(21, array_unique(array_map(static fn (array $c): string => $c[0], self::corpusAddresses())));
    }

    /**
     * The three script addresses in the corpus are the ones the native-script-spend fixture pays from. Their script
     * hashes have to be the hashes of the native scripts that same transaction carries as witnesses, which is a fact
     * about the chain rather than about this parser: the ledger would not have accepted the transaction otherwise.
     */
    public function test_a_script_address_in_the_corpus_names_a_script_the_transaction_witnesses(): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes('chain/native-script-spend.hex'));

        $witnessed = array_map(bin2hex(...), $transaction->witnessSet->nativeScriptHashes());
        $this->assertCount(3, $witnessed);

        $paidTo = [];

        foreach ($transaction->body->outputs() as $output) {
            $address = Address::fromHex($output->addressHex());

            if ($address->credential()->kind === CredentialKind::Script) {
                $paidTo[] = $address->credential()->hex();
            }
        }

        $this->assertNotEmpty($paidTo, 'The native script spend fixture pays to no script address.');

        foreach ($paidTo as $hash) {
            $this->assertContains($hash, $witnessed, 'A script address is paid without its script being witnessed.');
        }
    }

    /**
     * The same script hash, written as an address on the other network. The bytes differ in exactly one byte and the
     * bech32 differs everywhere, which is what a network tag is for.
     */
    public function test_a_corpus_script_address_moved_to_preprod_keeps_its_script_hash(): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes('chain/native-script-spend.hex'));

        foreach ($transaction->body->outputs() as $output) {
            $mainnet = Address::fromHex($output->addressHex());

            if ($mainnet->credential()->kind !== CredentialKind::Script) {
                continue;
            }

            $preprod = EnterpriseAddress::of(Network::named('preprod'), $mainnet->credential());

            $this->assertSame($mainnet->credential()->hex(), $preprod->credential()->hex());
            $this->assertSame(substr($mainnet->toHex(), 2), substr($preprod->toHex(), 2));
            $this->assertNotSame($mainnet->toHex(), $preprod->toHex());
            $this->assertStringStartsWith('addr_test1', $preprod->toBech32());

            return;
        }

        $this->fail('The native script spend fixture pays to no script address.');
    }
}
