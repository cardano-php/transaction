<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Primitives\AssetBundle;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\TransactionInput;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Primitives\WitnessSet;
use Cardano\Transaction\Script\NativeScript;
use Cardano\Transaction\Signing\SigningKey;
use RuntimeException;

/**
 * Reads the committed Mesh vectors and rebuilds each one with this package's builder.
 *
 * The vectors file holds two descriptions of every case: `spec`, which is the transaction in neutral terms, and
 * `mesh`, which is what @meshsdk/core-cst made of that spec. Only `spec` is read here. Nothing in this class looks at
 * the Mesh side, so the PHP body is built from the same description rather than from the answer.
 *
 * Asset ordering is the one place where the builder is told which of two valid arrangements to use, because that is
 * the one place the two implementations legitimately disagree; DifferentialVectorTest asserts both arrangements, one
 * structurally and one byte for byte.
 */
final class DifferentialVectors
{
    public static function path(): string
    {
        return __DIR__.'/vectors/differential/vectors.json';
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $contents = file_get_contents(self::path());

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.self::path());
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException(self::path().' is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::all()['cases'] as $case) {
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function feeWidths(): array
    {
        $widths = self::all()['fee_field_widths'];

        $cases = [];
        foreach ($widths['cases'] as $case) {
            $cases['fee '.$case['fee']] = [$case['fee'], $case['body_cbor'], $case['body_hash']];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    public static function feeBase(): array
    {
        return self::all()['fee_field_widths']['base'];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public static function body(array $spec, bool $canonicalAssets = true): TransactionBody
    {
        $builder = TransactionBodyBuilder::create()
            ->inputs(array_map(self::input(...), $spec['inputs']))
            ->outputs(array_map(
                static fn (array $output): TransactionOutput => TransactionOutput::create(
                    self::bytes($output['address']),
                    self::value($output, $canonicalAssets)
                ),
                $spec['outputs']
            ))
            ->fee($spec['fee']);

        if (isset($spec['ttl'])) {
            $builder->ttl($spec['ttl']);
        }

        if (isset($spec['validity_interval_start'])) {
            $builder->validityIntervalStart($spec['validity_interval_start']);
        }

        if (isset($spec['auxiliary_data_hash'])) {
            $builder->auxiliaryDataHash(self::bytes($spec['auxiliary_data_hash']));
        }

        if (isset($spec['mint'])) {
            $builder->mint(self::multiAsset($spec['mint'], $canonicalAssets));
        }

        if (isset($spec['required_signers'])) {
            $builder->requiredSigners(array_map(self::bytes(...), $spec['required_signers']));
        }

        if (isset($spec['network_id'])) {
            $builder->networkId($spec['network_id']);
        }

        if (isset($spec['collateral'])) {
            $builder->collateral(array_map(self::input(...), $spec['collateral']));
        }

        if (isset($spec['reference_inputs'])) {
            $builder->referenceInputs(array_map(self::input(...), $spec['reference_inputs']));
        }

        return $builder->build();
    }

    /**
     * The witness set a case describes: the given number of witnesses of the right length holding zeroes, plus any
     * native scripts.
     *
     * The zeroes are the point. A fee is charged on the witnessed transaction and has to be settled before anything
     * can be signed, so the transaction is measured carrying witnesses that are the right size and verify against
     * nothing. No vector in this directory carries a private key or a real signature.
     *
     * @param  array<string, mixed>  $witnesses
     */
    public static function witnessSet(array $witnesses): WitnessSet
    {
        $plan = \Cardano\Transaction\Ledger\WitnessPlan::forSignatures($witnesses['dummy_signatures']);

        return WitnessSet::of(
            $plan->dummyWitnesses(),
            array_map(
                static fn (array $script): NativeScript => NativeScript::fromArray($script),
                $witnesses['native_scripts'] ?? []
            )
        );
    }

    /**
     * A key that exists for the length of one test and is never written anywhere.
     */
    public static function ephemeralKey(): SigningKey
    {
        return SigningKey::generate();
    }

    public static function bytes(string $hex): string
    {
        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new RuntimeException('Not hexadecimal: '.$hex);
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function input(array $input): TransactionInput
    {
        return TransactionInput::of(self::bytes($input['transaction_id']), $input['index']);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private static function value(array $output, bool $canonical): Value
    {
        if (! isset($output['assets'])) {
            return Value::lovelace($output['coin']);
        }

        return Value::of($output['coin'], self::multiAsset($output['assets'], $canonical));
    }

    /**
     * @param  list<array<string, mixed>>  $bundles
     */
    private static function multiAsset(array $bundles, bool $canonical): MultiAsset
    {
        $built = array_map(
            static fn (array $bundle): AssetBundle => AssetBundle::of(
                self::bytes($bundle['policy_id']),
                array_map(
                    static fn (array $asset): array => [self::bytes($asset['name']), $asset['quantity']],
                    $bundle['assets']
                )
            ),
            $bundles
        );

        return $canonical ? MultiAsset::canonical($built) : MultiAsset::of($built);
    }
}
