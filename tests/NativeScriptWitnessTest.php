<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Primitives\AssetBundle;
use PHPUnit\Framework\TestCase;

/**
 * What a native script in the witness set is witnessing.
 *
 * The check is a known answer of the same kind as the transaction hash. A minting policy id is the hash of the script
 * that governs it, so on the CIP-25 fixture the policy id the chain recorded and the hash computed here from the
 * witness set have to be the same twenty-eight bytes. If the script bytes were read back even slightly wrong the two
 * would not meet, which makes this an independent check on how the witness set was taken apart.
 *
 * It is also how the corpus was selected: a script whose hash is not a minted policy id, in a transaction with no
 * certificates and no withdrawals, can only be witnessing a spend.
 */
class NativeScriptWitnessTest extends TestCase
{
    public function test_the_mint_policy_id_is_the_hash_of_the_native_script_that_governs_it(): void
    {
        $fixture = TransactionFixtures::chainFixture('cip25-mint');
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        $hashes = array_map('bin2hex', $transaction->witnessSet->nativeScriptHashes());
        $policies = array_map(
            static fn (AssetBundle $bundle): string => $bundle->policyIdHex(),
            $transaction->body->mint()->bundles()
        );

        $this->assertNotEmpty($policies);
        foreach ($policies as $policy) {
            $this->assertContains(
                $policy,
                $hashes,
                'The minted policy '.$policy.' has no native script in the witness set that hashes to it.'
            );
        }
    }

    public function test_a_native_script_spend_witnesses_no_minting_policy(): void
    {
        $fixture = TransactionFixtures::chainFixture('native-script-spend');
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        $hashes = $transaction->witnessSet->nativeScriptHashes();

        $this->assertCount(3, $hashes);
        $this->assertNull($transaction->body->mint());

        foreach ($hashes as $hash) {
            $this->assertSame(28, strlen($hash));
        }
    }

    public function test_a_transaction_with_no_native_scripts_has_no_script_hashes(): void
    {
        $fixture = TransactionFixtures::chainFixture('plain-ada-payment');
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        $this->assertSame([], $transaction->witnessSet->nativeScriptHashes());
    }
}
