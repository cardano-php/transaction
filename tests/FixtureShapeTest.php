<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Primitives\AuxiliaryData;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\WitnessSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shape a fixture claims is checked against the decoded transaction.
 *
 * A manifest tag is an assertion about a transaction, and an unchecked one is a label. The plan makes custody
 * precondition 1 countable by naming fifteen shapes and requiring at least one fixture for each, which is only a
 * count worth having if the tags are true. A tag with no check here fails rather than passing quietly.
 */
class FixtureShapeTest extends TestCase
{
    public static function chainFixtures(): array
    {
        return TransactionFixtures::chainFixtures();
    }

    #[DataProvider('chainFixtures')]
    public function test_a_fixture_has_every_shape_it_claims(array $fixture): void
    {
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        foreach ($fixture['shapes'] as $shape) {
            $this->assertShape($shape, $transaction, $fixture['id']);
        }
    }

    public function test_every_shape_in_the_catalogue_has_a_fixture(): void
    {
        foreach (TransactionFixtures::section('shapes') as $shape) {
            $this->assertNotEmpty(
                TransactionFixtures::fixturesWithShape($shape['id']),
                'No fixture carries the shape '.$shape['id'].'.'
            );
        }
    }

    /**
     * The plan enumerates fifteen shapes and makes "one fixture each" the first custody precondition. Its eleventh
     * pairs required signers with the network id; no transaction in the scanned pool carried both, so the catalogue
     * splits it in two and this is where that count is still checked as fifteen.
     */
    public function test_all_fifteen_shapes_the_plan_enumerates_are_covered(): void
    {
        $covered = [];
        foreach (TransactionFixtures::section('shapes') as $shape) {
            if ($shape['plan_item'] !== null && TransactionFixtures::fixturesWithShape($shape['id']) !== []) {
                $covered[$shape['plan_item']] = true;
            }
        }

        $this->assertSame(range(1, 15), array_keys($covered));
    }

    public function test_every_shape_a_fixture_claims_is_in_the_catalogue(): void
    {
        $catalogue = array_column(TransactionFixtures::section('shapes'), 'id');

        foreach (TransactionFixtures::section('chain') as $fixture) {
            foreach ($fixture['shapes'] as $shape) {
                $this->assertContains(
                    $shape,
                    $catalogue,
                    $fixture['id'].' claims the shape '.$shape.', which the catalogue does not describe.'
                );
            }
        }
    }

    private function assertShape(string $shape, Transaction $transaction, string $id): void
    {
        $body = $transaction->body;
        $withAssets = array_filter(
            $body->outputs(),
            static fn (TransactionOutput $output): bool => $output->value->hasAssets()
                && $output->value->assetCount() > 0
        );

        match ($shape) {
            'plain-ada-payment' => $this->assertPlainAdaPayment($transaction, $id),
            'single-multi-asset-output' => $this->assertCount(1, $withAssets, $id),
            'many-multi-asset-outputs' => $this->assertGreaterThanOrEqual(2, count($withAssets), $id),
            'native-script-spend' => $this->assertNativeScriptSpend($transaction, $id),
            'cip25-mint' => $this->assertCip25Mint($transaction, $id),
            'cip20-message' => $this->assertTrue(
                $transaction->auxiliaryData?->hasLabel(674) ?? false,
                $id.' claims a CIP-20 message and carries no label 674.'
            ),
            'both-validity-bounds' => $this->assertBounds($transaction, true, true, $id),
            'upper-bound-only' => $this->assertBounds($transaction, true, false, $id),
            'lower-bound-only' => $this->assertBounds($transaction, false, true, $id),
            'collateral' => $this->assertNotEmpty($body->collateral(), $id.' claims collateral and has none.'),
            'required-signers' => $this->assertRequiredSigners($transaction, $id),
            'network-id' => $this->assertNetworkId($transaction, $id),
            'reference-inputs' => $this->assertNotEmpty(
                $body->referenceInputs(),
                $id.' claims reference inputs and has none.'
            ),
            'exact-value-spend-no-change' => $this->assertCount(1, $body->outputs(), $id),
            'legacy-array-output' => $this->assertOutputForm($transaction, TransactionOutput::FORM_LEGACY, $id),
            'map-output' => $this->assertOutputForm($transaction, TransactionOutput::FORM_MAP, $id),
            'set-tag-258-inputs' => $this->assertNotNull(
                $body->inputSetForm(TransactionBody::FIELD_INPUTS)?->setTagAdditionalInformation,
                $id.' claims a tag 258 input set and its inputs are a bare array.'
            ),
            'pre-alonzo-three-item' => $this->assertSame(3, $transaction->topLevelItemCount(), $id),
            'shelley-map-auxiliary-data' => $this->assertSame(
                AuxiliaryData::FORM_SHELLEY,
                $transaction->auxiliaryData?->form,
                $id.' claims the Shelley auxiliary data form.'
            ),
            default => $this->fail('No check is written for the shape '.$shape.', claimed by '.$id.'.'),
        };
    }

    private function assertPlainAdaPayment(Transaction $transaction, string $id): void
    {
        $body = $transaction->body;

        foreach ($body->outputs() as $index => $output) {
            $this->assertFalse(
                $output->value->hasAssets() && $output->value->assetCount() > 0,
                sprintf('%s: output %d carries assets.', $id, $index)
            );
        }

        $this->assertNull($body->mint(), $id.' mints.');
        $this->assertFalse($body->has(TransactionBody::FIELD_CERTIFICATES), $id.' carries certificates.');
        $this->assertFalse($body->has(TransactionBody::FIELD_WITHDRAWALS), $id.' carries withdrawals.');
        $this->assertEmpty($body->collateral(), $id.' carries collateral, so a script is running.');
        $this->assertEmpty($transaction->witnessSet->nativeScripts(), $id.' carries native scripts.');

        foreach ([
            WitnessSet::FIELD_PLUTUS_V1_SCRIPTS,
            WitnessSet::FIELD_PLUTUS_DATA,
            WitnessSet::FIELD_REDEEMERS,
            WitnessSet::FIELD_PLUTUS_V2_SCRIPTS,
            WitnessSet::FIELD_PLUTUS_V3_SCRIPTS,
        ] as $field) {
            $this->assertFalse(
                $transaction->witnessSet->has($field),
                sprintf('%s: witness set field %d is present.', $id, $field)
            );
        }
    }

    /**
     * A native script in the witness set is witnessing a mint, a certificate, a withdrawal or a spend. With no mint
     * field, no certificates and no withdrawals, only the spend is left, which is why those three are asserted here
     * rather than the input addresses: the addresses live in the outputs of earlier transactions the corpus does not
     * hold. The manifest records the three script addresses Koios reports for this one.
     */
    private function assertNativeScriptSpend(Transaction $transaction, string $id): void
    {
        $this->assertNotEmpty($transaction->witnessSet->nativeScripts(), $id.' carries no native script.');
        $this->assertNull($transaction->body->mint(), $id.' mints, so a native script could be a policy.');
        $this->assertFalse($transaction->body->has(TransactionBody::FIELD_CERTIFICATES), $id.' carries certificates.');
        $this->assertFalse($transaction->body->has(TransactionBody::FIELD_WITHDRAWALS), $id.' carries withdrawals.');

        foreach ($transaction->witnessSet->nativeScriptBytes() as $script) {
            $this->assertNotSame('', $script);
        }
    }

    private function assertCip25Mint(Transaction $transaction, string $id): void
    {
        $mint = $transaction->body->mint();

        $this->assertNotNull($mint, $id.' claims a CIP-25 mint and has no mint field.');
        $this->assertGreaterThanOrEqual(1, $mint->policyCount());
        $this->assertTrue(
            $transaction->auxiliaryData?->hasLabel(721) ?? false,
            $id.' mints but carries no label 721.'
        );
    }

    private function assertBounds(Transaction $transaction, bool $upper, bool $lower, string $id): void
    {
        $this->assertSame($upper, $transaction->body->ttl() !== null, $id.': upper bound.');
        $this->assertSame($lower, $transaction->body->validityIntervalStart() !== null, $id.': lower bound.');
    }

    private function assertRequiredSigners(Transaction $transaction, string $id): void
    {
        $signers = $transaction->body->requiredSigners();

        $this->assertNotEmpty($signers, $id.' claims required signers and has none.');
        foreach ($signers as $signer) {
            $this->assertSame(28, strlen($signer), $id.': a required signer is not a 28 byte key hash.');
        }
    }

    private function assertNetworkId(Transaction $transaction, string $id): void
    {
        $networkId = $transaction->body->networkId();

        $this->assertNotNull($networkId, $id.' claims a network id and has none.');
        $this->assertTrue(
            $networkId->equalsInt(0) || $networkId->equalsInt(1),
            $id.': the network id is '.$networkId->value.'.'
        );
    }

    private function assertOutputForm(Transaction $transaction, string $form, string $id): void
    {
        $forms = array_map(
            static fn (TransactionOutput $output): string => $output->form,
            $transaction->body->outputs()
        );

        $this->assertContains($form, $forms, $id.' claims a '.$form.' form output and has none.');
    }
}
