<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Script\NativeScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ten real mainnet native scripts, each checked against the hash the chain knows it by.
 *
 * This is the assertion that a script encoder cannot pass by agreeing with itself. A /script_info reply carries the
 * script and its hash as two separate fields, and the hash is the one the ledger assigned when the script was used.
 * Reproducing it means the constructor tags, the integer widths, the array lengths, the language tag byte and the
 * digest length are all right at once; getting any one of them wrong changes the hash and nothing else.
 *
 * See tests/fixtures/cardano-scripts/README.md for where they came from and how they were chosen.
 */
class NativeScriptCorpusTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return JsonFixture::read('cardano-scripts/script-info.json');
    }

    public static function recordedScripts(): array
    {
        $cases = [];

        foreach (self::fixture()['scripts'] as $script) {
            $cases[$script['script_hash']] = [$script];
        }

        return $cases;
    }

    /**
     * The assertion the whole fixture exists for.
     */
    #[DataProvider('recordedScripts')]
    public function test_a_recorded_script_hashes_to_the_hash_the_chain_indexed_it_under(array $recorded): void
    {
        $script = NativeScript::fromArray($recorded['script']);

        $this->assertSame($recorded['script_hash'], $script->hashHex());
    }

    #[DataProvider('recordedScripts')]
    public function test_a_recorded_script_serializes_to_the_reference_encoders_bytes(array $recorded): void
    {
        $script = NativeScript::fromArray($recorded['script']);

        $this->assertSame($recorded['cbor'], $script->cborHex());
    }

    /**
     * The same bytes read back. A script arriving in a witness set is CBOR, not JSON, and a decoder that disagreed
     * with the encoder about any of it would show up here rather than the first time a script was spent.
     */
    #[DataProvider('recordedScripts')]
    public function test_a_recorded_script_survives_a_round_trip_through_its_own_bytes(array $recorded): void
    {
        $fromCbor = NativeScript::fromCbor((string) hex2bin($recorded['cbor']));

        $this->assertSame($recorded['cbor'], $fromCbor->cborHex());
        $this->assertSame($recorded['script_hash'], $fromCbor->hashHex());
        $this->assertSame(self::orderedByKey($recorded['script']), self::orderedByKey($fromCbor->toArray()));
    }

    /**
     * The policy identifier of a native minting policy is its script hash. Three of these scripts are the ones that
     * minted assets in the transaction corpus, so the chain has already agreed.
     */
    #[DataProvider('recordedScripts')]
    public function test_a_recorded_scripts_policy_id_is_its_script_hash(array $recorded): void
    {
        $script = NativeScript::fromArray($recorded['script']);

        $this->assertSame($recorded['script_hash'], $script->policyId());
        $this->assertSame($recorded['script_hash'], $script->credential()->hex());
        $this->assertTrue($script->credential()->isScript());
    }

    #[DataProvider('recordedScripts')]
    public function test_a_recorded_script_pays_to_the_reference_implementations_address(array $recorded): void
    {
        $script = NativeScript::fromArray($recorded['script']);

        $this->assertSame(
            $recorded['enterprise_address_mainnet'],
            $script->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
        $this->assertSame(
            $recorded['enterprise_address_preprod'],
            $script->enterpriseAddress(Network::named('preprod'))->toBech32(),
        );
    }

    /**
     * The fixture is only worth what it covers. Ten scripts that were all `sig` would pass every assertion above and
     * say nothing about the other five constructors.
     */
    public function test_the_recorded_scripts_cover_every_constructor(): void
    {
        $seen = [];

        foreach (self::fixture()['scripts'] as $recorded) {
            foreach ($recorded['constructors'] as $constructor) {
                $seen[$constructor] = true;
            }

            // The recorded classification has to match the script, or a fixture could claim coverage it has not got.
            $this->assertSame(
                $recorded['constructors'],
                self::constructorsIn($recorded['script']),
                $recorded['script_hash'].' does not use the constructors the fixture says it does.'
            );
        }

        ksort($seen);

        $this->assertSame(['after', 'all', 'any', 'atLeast', 'before', 'sig'], array_keys($seen));
    }

    public function test_the_recorded_scripts_include_one_nested_three_deep(): void
    {
        $depths = array_map(static fn (array $s): int => $s['depth'], self::fixture()['scripts']);

        $this->assertSame(3, max($depths));
    }

    /**
     * The native scripts the transaction corpus carries in its witness sets, hashed two ways.
     *
     * WitnessSet::nativeScriptHashes() hashes the bytes as they arrived. This model rebuilds the bytes from a decoded
     * script and hashes those. The two agreeing is what says a script can be taken apart and put back together
     * without moving the address it locks.
     */
    public function test_every_native_script_in_the_transaction_corpus_decodes_and_hashes_the_same(): void
    {
        $checked = 0;

        foreach (TransactionFixtures::chainFixtures() as $case) {
            $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($case[0]['file']));
            $hashes = $transaction->witnessSet->nativeScriptHashes();

            foreach ($transaction->witnessSet->nativeScriptBytes() as $index => $bytes) {
                $script = NativeScript::fromCbor($bytes);

                $this->assertSame(bin2hex($bytes), $script->cborHex(), $case[0]['id'].' script '.$index);
                $this->assertSame(bin2hex($hashes[$index]), $script->hashHex(), $case[0]['id'].' script '.$index);
                $checked++;
            }
        }

        $this->assertSame(4, $checked, 'The corpus no longer carries the four native scripts this test read.');
    }

    /**
     * The same script with the fields of each clause in a fixed order.
     *
     * A provider writes `scripts` before `required` and cardano-cli writes them the other way round, and neither is
     * more correct: JSON object keys carry no order. The list under `scripts` is left alone, because the order of the
     * sub-scripts is part of what a script says and moving one moves the hash.
     *
     * @return array<string, mixed>
     */
    private static function orderedByKey(array $script): array
    {
        ksort($script);

        if (isset($script['scripts'])) {
            $script['scripts'] = array_map(self::orderedByKey(...), $script['scripts']);
        }

        return $script;
    }

    /**
     * @return list<string>
     */
    private static function constructorsIn(array $script): array
    {
        $seen = [$script['type'] => true];

        foreach ($script['scripts'] ?? [] as $child) {
            foreach (self::constructorsIn($child) as $constructor) {
                $seen[$constructor] = true;
            }
        }

        ksort($seen);

        return array_keys($seen);
    }
}
