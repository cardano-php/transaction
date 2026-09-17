<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\TransactionInput;
use Cardano\Transaction\Primitives\TransactionOutput;

/**
 * A transaction reduced to what it says, with how it was written thrown away.
 *
 * This is what "compare structurally" means in the differential test, and it is worth being exact about, because a
 * comparison that throws away too much passes on transactions that are not the same one.
 *
 * Three things are normalized and nothing else is:
 *
 * 1. **The output form.** Babbage writes an output as a two-item array or as a map keyed by small integers. Both are
 *    on mainnet, both are accepted, and neither can be rewritten as the other because the form is in the bytes that
 *    were hashed. This package's builder writes the map form and Mesh writes the array form, so the form is dropped
 *    here and the address and value inside it are compared.
 * 2. **Multiasset map order.** The ledger takes a multiasset map in any order. Assets are collected into a map keyed
 *    by policy and name, so order stops being visible; quantities, names, policies and the count of each are not
 *    touched.
 * 3. **Integer width.** A CBOR integer can be written in five widths and the value is the same in all of them. The
 *    value is compared as a decimal string, the width is not.
 *
 * Everything else is compared as it stands, including which body fields are present, in which order, and the
 * position of every input and output. Dropping field order would hide a body written with its fields shuffled, and
 * dropping output order would hide two outputs swapped, which is a different transaction paying different people.
 */
final class TransactionShape
{
    /**
     * @return array<string, mixed>
     */
    public static function ofBody(TransactionBody $body): array
    {
        return [
            'auxiliary_data_hash' => self::hex($body->auxiliaryDataHash()),
            'collateral' => array_map(self::input(...), $body->collateral()),
            'fee' => $body->fee()->value,
            'field_keys' => $body->fieldKeys(),
            'inputs' => array_map(self::input(...), $body->inputs()),
            'mint' => self::assets($body->mint()),
            'network_id' => $body->networkId()?->value,
            'outputs' => array_map(self::output(...), $body->outputs()),
            'reference_inputs' => array_map(self::input(...), $body->referenceInputs()),
            'required_signers' => array_map(static fn (string $s): string => bin2hex($s), $body->requiredSigners()),
            'script_data_hash' => self::hex($body->scriptDataHash()),
            'total_collateral' => $body->totalCollateral()?->value,
            'ttl' => $body->ttl()?->value,
            'validity_interval_start' => $body->validityIntervalStart()?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofTransaction(Transaction $transaction): array
    {
        return [
            'auxiliary_data' => $transaction->auxiliaryDataBytes() === null
                ? null
                : bin2hex($transaction->auxiliaryDataBytes()),
            'body' => self::ofBody($transaction->body),
            'is_valid' => $transaction->isValid,
            'top_level_items' => $transaction->topLevelItemCount(),
            'witness_set' => self::ofWitnessSet($transaction),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ofWitnessSet(Transaction $transaction): array
    {
        return [
            'field_keys' => $transaction->witnessSet->fieldKeys(),
            'native_scripts' => array_map(
                static fn (string $bytes): string => bin2hex($bytes),
                $transaction->witnessSet->nativeScriptBytes()
            ),
            'vkey_witnesses' => array_map(
                static fn ($witness): array => [
                    'signature' => bin2hex($witness->signature),
                    'vkey' => bin2hex($witness->vkey),
                ],
                $transaction->witnessSet->vkeyWitnesses()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function input(TransactionInput $input): array
    {
        return ['index' => $input->index->value, 'transaction_id' => $input->transactionIdHex()];
    }

    /**
     * @return array<string, mixed>
     */
    private static function output(TransactionOutput $output): array
    {
        return [
            'address' => bin2hex($output->address),
            'assets' => self::assets($output->value->assets),
            'coin' => $output->value->coin->value,
        ];
    }

    /**
     * @return array<string, array<string, string>>|null
     */
    private static function assets(?MultiAsset $assets): ?array
    {
        if ($assets === null) {
            return null;
        }

        $byPolicy = [];
        foreach ($assets->bundles() as $bundle) {
            $byPolicy[$bundle->policyIdHex()] = self::bundle($bundle);
        }

        ksort($byPolicy);

        return $byPolicy;
    }

    /**
     * @return array<string, string>
     */
    private static function bundle(AssetBundle $bundle): array
    {
        $names = [];
        foreach ($bundle->assets() as [$name, $quantity]) {
            $names[bin2hex($name)] = ($quantity->negative ? '-' : '').$quantity->value;
        }

        ksort($names);

        return $names;
    }

    private static function hex(?string $bytes): ?string
    {
        return $bytes === null ? null : bin2hex($bytes);
    }
}
