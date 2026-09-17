<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Primitives\AuxiliaryData;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The same transaction, built twice by two implementations that share no code.
 *
 * The other side is @meshsdk/core-cst over @cardano-sdk/core, which is the serializer behind MeshJS and is in daily
 * use on mainnet. It was run once by tests/vectors/differential/generate.mjs and what it produced is committed, so
 * this test needs no Node and no network. Regenerating is a deliberate act with a reviewable diff, which is what a
 * vector is for; a suite that rebuilt them on every run would agree with whatever the reference did that morning.
 *
 * **Why some of this is structural and some of it is byte for byte.**
 *
 * Both implementations are correct and they make two encoding choices differently. Neither choice changes what the
 * transaction does and both produce bytes the ledger accepts, but each changes the body hash, so a flat byte
 * comparison over everything would fail for reasons that teach nobody anything.
 *
 * 1. **Output form.** Babbage allows an output as a two-item array or as a map keyed by small integers.
 *    TransactionOutput::create writes the map form, deliberately, and Mesh writes the array form for an output with
 *    no datum and no script reference. Compared structurally: address and value, not the container. The byte level
 *    is not given up, though. test_a_body_built_with_mesh_outputs_is_byte_identical below rebuilds the body with the
 *    outputs decoded out of Mesh's own bytes and requires the result to be Mesh's body exactly, which says that the
 *    output container is the only thing the two implementations write differently.
 *
 * 2. **Multiasset map order.** The ledger takes a multiasset map in any order. Mesh sorts it canonically, by the
 *    encoded key, so shorter asset names first. This package's builder writes whatever order it is handed, and
 *    MultiAsset::canonical is the helper that produces Mesh's order. Both are asserted: the spec order structurally,
 *    the canonical order byte for byte.
 *
 * Everything else is compared byte for byte, hash included: the field set and its order, the input sets, the fee and
 * its width, both validity bounds, the mint field, required signers, the network id, collateral, reference inputs,
 * the auxiliary data and its hash, the witness set, and the four item transaction the whole thing is wrapped in.
 */
class DifferentialVectorTest extends TestCase
{
    public static function vectors(): array
    {
        return DifferentialVectors::cases();
    }

    public static function feeWidths(): array
    {
        return DifferentialVectors::feeWidths();
    }

    /**
     * Mesh's bytes, read by this package's decoder, re-encoded and re-hashed, come back unchanged.
     *
     * This is the corpus assertion applied to a transaction nothing on chain has seen. It separates two questions
     * that a build comparison runs together: whether this package can read and hash what another implementation
     * writes, and whether it writes the same thing itself. A failure here is a decoder failure; a failure in the
     * builder tests below, with this one passing, is a builder failure.
     */
    #[DataProvider('vectors')]
    public function test_mesh_bytes_round_trip_through_this_decoder(array $case): void
    {
        $bytes = DifferentialVectors::bytes($case['mesh']['transaction_cbor']);

        $transaction = TransactionDecoder::decode($bytes);

        $this->assertSame(
            bin2hex($bytes),
            bin2hex($transaction->encode()),
            $case['id'].': Mesh bytes do not survive a round trip through this decoder.'
        );

        $this->assertSame(
            $case['mesh']['body_hash'],
            $transaction->hashHex(),
            $case['id'].': this package hashes Mesh\'s body to something other than Mesh does.'
        );

        $this->assertSame(
            $case['mesh']['transaction_id'],
            $transaction->hashHex(),
            $case['id'].': Mesh\'s transaction id is not the hash of its own body.'
        );
    }

    /**
     * The body this package builds from the spec says the same thing as the body Mesh built from it.
     *
     * Assets are handed to the builder in the order the spec declares them, which for one case is deliberately not
     * canonical order, so this assertion is the one that has to survive the two implementations arranging a
     * multiasset map differently.
     */
    #[DataProvider('vectors')]
    public function test_a_body_built_here_matches_mesh_structurally(array $case): void
    {
        $mine = DifferentialVectors::body($case['spec'], canonicalAssets: false);
        $theirs = $this->meshBody($case);

        $this->assertSame(
            TransactionShape::ofBody($theirs),
            TransactionShape::ofBody($mine),
            $case['id'].': the body built here is not the body Mesh built from the same description.'
        );
    }

    /**
     * With the same encoding choices, the same bytes.
     *
     * The outputs are taken from Mesh's own decoded body, which settles the output form, and the assets are put in
     * canonical order, which settles the map ordering. Nothing else is borrowed: every other field is built here
     * from the spec. If the two implementations disagreed anywhere but those two places, this is where it shows,
     * because what comes out has to be Mesh's body byte for byte and therefore hash for hash.
     */
    #[DataProvider('vectors')]
    public function test_a_body_built_with_mesh_outputs_is_byte_identical(array $case): void
    {
        $theirs = $this->meshBody($case);
        $rebuilt = $this->rebuildWithOutputs($case, $theirs);

        $this->assertSame(
            bin2hex($theirs->encode()),
            bin2hex($rebuilt->encode()),
            $case['id'].': with Mesh\'s outputs and canonical asset order the bodies still differ.'
        );

        $this->assertSame(
            $case['mesh']['body_hash'],
            $rebuilt->hashHex(),
            $case['id'].': the rebuilt body does not hash to Mesh\'s transaction hash.'
        );
    }

    /**
     * Where the assets are put in canonical order, the value inside the output is Mesh's bytes exactly.
     *
     * Only the cases that carry assets say anything here, and the one whose spec declares them out of order says the
     * most: it is the case where the builder's own ordering and Mesh's disagree, and canonical ordering is what
     * closes the gap.
     */
    #[DataProvider('vectors')]
    public function test_canonical_asset_order_reproduces_mesh_value_bytes(array $case): void
    {
        $theirs = $this->meshBody($case);
        $mine = DifferentialVectors::body($case['spec'], canonicalAssets: true);

        $carriesAssets = false;

        foreach ($theirs->outputs() as $index => $output) {
            if (! $output->value->hasAssets()) {
                continue;
            }

            $carriesAssets = true;

            $this->assertSame(
                bin2hex($output->value->encode()),
                bin2hex($mine->outputs()[$index]->value->encode()),
                $case['id'].sprintf(': output %d is not the value Mesh wrote.', $index)
            );
        }

        if ($theirs->mint() !== null) {
            $carriesAssets = true;

            $this->assertSame(
                bin2hex(CborCodec::encode($theirs->mint()->toCbor())),
                bin2hex(CborCodec::encode($mine->mint()->toCbor())),
                $case['id'].': the mint field is not the one Mesh wrote.'
            );
        }

        if (! $carriesAssets) {
            $this->assertNull($theirs->mint(), $case['id'].' carries no assets, which is what this case is for.');
        }
    }

    /**
     * The witness set assembled here is Mesh's witness set, byte for byte.
     *
     * There is no legitimate divergence to allow for. A vkey witness is a definite two item array of a 32 byte
     * string and a 64 byte string, a native script has one encoding, and both sides write the fields in ascending
     * order with the empty ones left out.
     */
    #[DataProvider('vectors')]
    public function test_the_witness_set_is_byte_identical(array $case): void
    {
        $mine = DifferentialVectors::witnessSet($case['witnesses']);

        $this->assertSame(
            $case['mesh']['witness_set_cbor'],
            bin2hex(CborCodec::encode($mine->toCbor())),
            $case['id'].': the assembled witness set is not the one Mesh wrote.'
        );
    }

    /**
     * The whole four item transaction, assembled here, matches Mesh once the two output forms are reconciled.
     *
     * This is where the wrapper itself is checked: four items rather than three, the script validity flag written as
     * true, and auxiliary data attached rather than null where a case carries it.
     */
    #[DataProvider('vectors')]
    public function test_the_assembled_transaction_matches_mesh(array $case): void
    {
        $theirs = TransactionDecoder::decode(DifferentialVectors::bytes($case['mesh']['transaction_cbor']));

        $mine = Transaction::assemble(
            DifferentialVectors::body($case['spec'], canonicalAssets: false),
            DifferentialVectors::witnessSet($case['witnesses']),
            $this->meshAuxiliaryData($case),
        );

        $this->assertSame(
            TransactionShape::ofTransaction($theirs),
            TransactionShape::ofTransaction($mine),
            $case['id'].': the assembled transaction is not the one Mesh assembled.'
        );

        $rebuilt = Transaction::assemble(
            $this->rebuildWithOutputs($case, $theirs->body),
            DifferentialVectors::witnessSet($case['witnesses']),
            $this->meshAuxiliaryData($case),
        );

        $this->assertSame(
            $case['mesh']['transaction_cbor'],
            bin2hex($rebuilt->encode()),
            $case['id'].': with Mesh\'s outputs the assembled transaction still differs byte for byte.'
        );
    }

    /**
     * Auxiliary data is hashed into the body by the builder, not copied in from the vector.
     *
     * The body carries the hash and the transaction carries the data, and the ledger checks that they agree. Handing
     * the data to the builder is the arrangement where they cannot drift, and this is the assertion that the hash it
     * computes is the one an independent implementation computes over the same metadata.
     */
    #[DataProvider('vectors')]
    public function test_auxiliary_data_hashes_the_way_mesh_hashes_it(array $case): void
    {
        $auxiliary = $this->meshAuxiliaryData($case);

        if ($auxiliary === null) {
            $this->assertArrayNotHasKey(
                'auxiliary_data_hash',
                $case['spec'],
                $case['id'].' carries no auxiliary data but its spec carries a hash for some.'
            );

            return;
        }

        $this->assertSame(
            $case['spec']['auxiliary_data_hash'],
            $auxiliary->hashHex(),
            $case['id'].': this package hashes the auxiliary data to something other than Mesh does.'
        );

        $body = DifferentialVectors::body(
            array_diff_key($case['spec'], ['auxiliary_data_hash' => null]),
            canonicalAssets: true
        );

        $this->assertNull(
            $body->auxiliaryDataHash(),
            $case['id'].': a body built without auxiliary data should carry no hash for any.'
        );
    }

    /**
     * The fee field's width moves as the fee crosses a CBOR boundary, and it moves the same way on both sides.
     *
     * This is the arithmetic the fee fixed point walks over. Nine fees on one unchanging body, spanning every width
     * a CBOR unsigned integer has, and each one has to produce Mesh's bytes and Mesh's hash.
     */
    #[DataProvider('feeWidths')]
    public function test_the_fee_field_is_written_at_the_same_width_as_mesh(
        string $fee,
        string $bodyCbor,
        string $bodyHash
    ): void {
        $spec = DifferentialVectors::feeBase();
        $spec['fee'] = $fee;

        $theirs = TransactionBody::fromCbor(CborCodec::decode(DifferentialVectors::bytes($bodyCbor)));
        $mine = $this->rebuildWithOutputs(['spec' => $spec], $theirs);

        $this->assertSame(
            $bodyCbor,
            bin2hex($mine->encode()),
            sprintf('A fee of %s is not written the way Mesh writes it.', $fee)
        );

        $this->assertSame(
            $bodyHash,
            $mine->hashHex(),
            sprintf('A body carrying a fee of %s does not hash to what Mesh computed.', $fee)
        );
    }

    /**
     * The two output forms really are different bytes, so the structural comparison above is not vacuous.
     *
     * A normalization that quietly matched everything would make every assertion in this file pass. This is the
     * control: the form differs, the meaning does not, and both bodies are ones this package's decoder accepts.
     */
    public function test_the_two_output_forms_are_not_the_same_bytes(): void
    {
        $case = DifferentialVectors::cases()['plain-ada-payment'][0];

        $mine = DifferentialVectors::body($case['spec']);
        $theirs = $this->meshBody($case);

        $this->assertNotSame(
            bin2hex($theirs->encode()),
            bin2hex($mine->encode()),
            'The map form and the array form came out as the same bytes, which cannot be right.'
        );

        $this->assertNotSame(
            $theirs->hashHex(),
            $mine->hashHex(),
            'Two different bodies hashed to the same value.'
        );

        $this->assertSame('map', $mine->outputs()[0]->form);
        $this->assertSame('legacy', $theirs->outputs()[0]->form);
    }

    /**
     * The canonical ordering this package offers is not the ordering it writes by default.
     *
     * The same control, for the second divergence. If MultiAsset::of and MultiAsset::canonical produced the same
     * bytes for a deliberately unsorted bundle, the byte comparison in the canonical test would be proving nothing.
     */
    public function test_canonical_asset_order_is_not_declaration_order(): void
    {
        $case = DifferentialVectors::cases()['many-assets-one-policy'][0];

        $declared = DifferentialVectors::body($case['spec'], canonicalAssets: false);
        $canonical = DifferentialVectors::body($case['spec'], canonicalAssets: true);

        $this->assertNotSame(
            bin2hex($declared->outputs()[0]->value->encode()),
            bin2hex($canonical->outputs()[0]->value->encode()),
            'The spec declares this case\'s assets out of canonical order, so the two orderings must differ.'
        );

        $this->assertSame(
            TransactionShape::ofBody($declared),
            TransactionShape::ofBody($canonical),
            'Reordering a multiasset map changed what the body says, which it must not.'
        );
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function meshBody(array $case): TransactionBody
    {
        return TransactionBody::fromCbor(
            CborCodec::decode(DifferentialVectors::bytes($case['mesh']['body_cbor']))
        );
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function meshAuxiliaryData(array $case): ?AuxiliaryData
    {
        if (($case['mesh']['auxiliary_data_cbor'] ?? null) === null) {
            return null;
        }

        return AuxiliaryData::fromCbor(
            CborCodec::decode(DifferentialVectors::bytes($case['mesh']['auxiliary_data_cbor']))
        );
    }

    /**
     * This package's builder, fed the spec for every field except the outputs, which come from Mesh's decoded body.
     *
     * @param  array<string, mixed>  $case
     */
    private function rebuildWithOutputs(array $case, TransactionBody $source): TransactionBody
    {
        return $this->builderFor($case['spec'])->outputs($source->outputs())->build();
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function builderFor(array $spec): \Cardano\Transaction\Builder\TransactionBodyBuilder
    {
        // One placeholder output, replaced by the caller. The builder refuses a body with none, which is the
        // behaviour under test elsewhere and is not something to work around by making it optional.
        $spec['outputs'] = [['address' => bin2hex(str_repeat("\x00", 29)), 'coin' => '1']];

        $body = DifferentialVectors::body($spec, canonicalAssets: true);

        $builder = \Cardano\Transaction\Builder\TransactionBodyBuilder::create()
            ->inputs($body->inputs())
            ->fee($body->fee()->value);

        if ($body->ttl() !== null) {
            $builder->ttl($body->ttl()->value);
        }

        if ($body->validityIntervalStart() !== null) {
            $builder->validityIntervalStart($body->validityIntervalStart()->value);
        }

        if ($body->auxiliaryDataHash() !== null) {
            $builder->auxiliaryDataHash($body->auxiliaryDataHash());
        }

        if ($body->mint() !== null) {
            $builder->mint($body->mint());
        }

        if ($body->requiredSigners() !== []) {
            $builder->requiredSigners($body->requiredSigners());
        }

        if ($body->networkId() !== null) {
            $builder->networkId($body->networkId()->toInt());
        }

        if ($body->collateral() !== []) {
            $builder->collateral($body->collateral());
        }

        if ($body->referenceInputs() !== []) {
            $builder->referenceInputs($body->referenceInputs());
        }

        return $builder;
    }

    public function test_the_committed_vectors_say_where_they_came_from(): void
    {
        $generator = DifferentialVectors::all()['generator'];

        $this->assertSame('tests/vectors/differential/generate.mjs', $generator['script']);
        $this->assertArrayHasKey('@meshsdk/core-cst', $generator['packages']);
        $this->assertArrayHasKey('@cardano-sdk/core', $generator['packages']);
        $this->assertNotEmpty($generator['generated_at']);
    }

    /**
     * No vector carries a key or a signature that could ever be mistaken for a real one.
     *
     * Every witness in this directory is zeroes, which is what a fee is measured against and what no node will
     * accept. This is the assertion that keeps it that way: a regenerated vectors.json that started carrying real
     * signatures would fail here rather than quietly commit key material.
     */
    #[DataProvider('vectors')]
    public function test_no_vector_carries_a_usable_signature(array $case): void
    {
        $transaction = TransactionDecoder::decode(DifferentialVectors::bytes($case['mesh']['transaction_cbor']));

        $this->assertCount(
            $case['witnesses']['dummy_signatures'],
            $transaction->witnessSet->vkeyWitnesses(),
            $case['id'].' does not carry the number of witnesses its own description asks for.'
        );

        foreach ($transaction->witnessSet->vkeyWitnesses() as $index => $witness) {
            $this->assertSame(
                str_repeat("\x00", 32),
                $witness->vkey,
                $case['id'].sprintf(': witness %d carries a public key rather than zeroes.', $index)
            );

            $this->assertSame(
                str_repeat("\x00", 64),
                $witness->signature,
                $case['id'].sprintf(': witness %d carries a signature rather than zeroes.', $index)
            );

            $this->assertFalse(
                $witness->verifies(Blake2b::hash256($transaction->body->encode())),
                $case['id'].sprintf(': witness %d verifies, so it is not a dummy.', $index)
            );
        }
    }
}
