<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Primitives\AuxiliaryData;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\WitnessSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the model says about the corpus, beyond the four assertions.
 *
 * These are the reads a later step will make -- which fields the body carries, what an output is holding, which
 * metadata label sits where -- checked against every fixture so that a decoder which parsed the bytes correctly but
 * filed them in the wrong place is still caught.
 */
class TransactionModelTest extends TestCase
{
    private const KNOWN_BODY_FIELDS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22];

    public static function chainFixtures(): array
    {
        return TransactionFixtures::chainFixtures();
    }

    #[DataProvider('chainFixtures')]
    public function test_every_body_field_is_one_the_ledger_assigns(array $fixture): void
    {
        $body = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body;

        $this->assertNotEmpty($body->fieldKeys());

        foreach ($body->fieldKeys() as $field) {
            $this->assertContains($field, self::KNOWN_BODY_FIELDS, $fixture['id'].' carries body field '.$field.'.');
            $this->assertTrue($body->has($field));
        }

        foreach ([TransactionBody::FIELD_INPUTS, TransactionBody::FIELD_OUTPUTS, TransactionBody::FIELD_FEE] as $f) {
            $this->assertTrue($body->has($f), $fixture['id'].' is missing field '.$f.'.');
        }
    }

    #[DataProvider('chainFixtures')]
    public function test_every_witness_set_field_is_one_the_ledger_assigns(array $fixture): void
    {
        $witnessSet = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->witnessSet;

        foreach ($witnessSet->fieldKeys() as $field) {
            $this->assertContains($field, range(0, 7), $fixture['id'].' carries witness set field '.$field.'.');
        }

        $this->assertTrue($witnessSet->has(WitnessSet::FIELD_VKEY_WITNESSES), $fixture['id'].' has no vkey witness.');
    }

    #[DataProvider('chainFixtures')]
    public function test_every_input_names_a_thirty_two_byte_transaction(array $fixture): void
    {
        $body = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body;

        $this->assertNotEmpty($body->inputs());

        foreach (array_merge($body->inputs(), $body->collateral(), $body->referenceInputs()) as $input) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $input->transactionIdHex());
            $this->assertGreaterThanOrEqual(0, $input->index->toInt());
        }
    }

    #[DataProvider('chainFixtures')]
    public function test_every_output_has_an_address_and_a_coin(array $fixture): void
    {
        $body = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body;

        $this->assertNotEmpty($body->outputs());

        foreach ($body->outputs() as $index => $output) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]+$/',
                $output->addressHex(),
                sprintf('%s: output %d has an empty address.', $fixture['id'], $index)
            );
            $this->assertGreaterThan(0, $output->value->coin->toInt());
        }
    }

    /**
     * An inline datum and a script reference are fields of the map form. A positional output has neither, and its
     * optional third slot is a datum hash rather than a datum. Reporting one on the other would mean the form was
     * read wrong.
     */
    #[DataProvider('chainFixtures')]
    public function test_the_datum_accessors_agree_with_the_output_form(array $fixture): void
    {
        $body = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body;

        foreach ($body->outputs() as $index => $output) {
            $message = sprintf('%s: output %d', $fixture['id'], $index);

            if ($output->form === TransactionOutput::FORM_LEGACY) {
                $this->assertFalse($output->hasInlineDatum(), $message);
                $this->assertFalse($output->hasScriptReference(), $message);

                continue;
            }

            $this->assertSame(TransactionOutput::FORM_MAP, $output->form, $message);
            $this->assertFalse($output->hasDatumHash(), $message);
        }
    }

    #[DataProvider('chainFixtures')]
    public function test_a_multi_asset_output_names_a_policy_and_at_least_one_asset(array $fixture): void
    {
        $body = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body;

        foreach ($body->outputs() as $output) {
            $bundles = $output->value->assets?->bundles() ?? [];

            $this->assertSame(
                array_sum(array_map(static fn ($bundle): int => $bundle->count(), $bundles)),
                $output->value->assetCount(),
                'The asset count does not match what the bundles hold.'
            );

            foreach ($bundles as $bundle) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{56}$/', $bundle->policyIdHex());
                $this->assertGreaterThanOrEqual(1, $bundle->count());

                foreach ($bundle->assets() as [$name, $quantity]) {
                    $this->assertLessThanOrEqual(32, strlen($name));
                    $this->assertFalse($quantity->negative, 'An output quantity is negative.');
                }
            }
        }
    }

    public function test_a_metadata_label_is_reachable_and_an_absent_one_is_not(): void
    {
        $fixture = TransactionFixtures::chainFixture('cip20-message-tagged-auxiliary-data');
        $transaction = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']));

        $this->assertSame(AuxiliaryData::FORM_ALONZO, $transaction->auxiliaryData->form);
        $this->assertNotNull($transaction->auxiliaryData->metadataFor(674));
        $this->assertNull($transaction->auxiliaryData->metadataFor(721));
        $this->assertContains('674', $transaction->auxiliaryData->labels());
    }

    /**
     * A mint may burn, and a burn is written as a negative quantity. The fixture here mints, so every quantity is
     * positive; what the test holds is that the mint field is read with a signed integer, which the output path is
     * not allowed to do.
     */
    public function test_a_mint_quantity_is_read_as_a_signed_integer(): void
    {
        $fixture = TransactionFixtures::chainFixture('cip25-mint');
        $mint = TransactionDecoder::decode(TransactionFixtures::bytes($fixture['file']))->body->mint();

        $this->assertNotNull($mint);
        $this->assertSame(1, $mint->policyCount());
        $this->assertGreaterThanOrEqual(1, $mint->assetCount());

        foreach ($mint->bundles() as $bundle) {
            foreach ($bundle->assets() as [$name, $quantity]) {
                $this->assertFalse($quantity->negative);
                $this->assertSame('1', $quantity->value);
            }
        }
    }
}
