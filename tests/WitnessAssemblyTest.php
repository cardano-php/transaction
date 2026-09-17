<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\FeeFixedPoint;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\WitnessPlan;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\TransactionInput;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Primitives\VkeyWitness;
use Cardano\Transaction\Primitives\WitnessSet;
use Cardano\Transaction\Script\NativeScript;
use Cardano\Transaction\Signing\SigningKey;
use Cardano\Transaction\Signing\TransactionSigner;
use PHPUnit\Framework\TestCase;

/**
 * Putting a transaction together: the witness set, the swap from measuring witnesses to real ones, and the order the
 * whole thing has to happen in.
 *
 * The order is the part worth stating. A fee is charged on the witnessed bytes and lives in the body, and the
 * signatures are over the body, so nothing can be signed until the fee stops moving and the fee cannot stop moving
 * until the transaction is the size it will be submitted at. Building with witnesses of the right length holding
 * zeroes is what breaks the circle, and the assertions here are about that swap being exact: the same number of
 * witnesses, the same number of bytes, the same body, and therefore the same hash.
 *
 * Every key in this file is generated inside the test that uses it and discarded before that test returns.
 */
class WitnessAssemblyTest extends TestCase
{
    private const ADDRESS = "\x00";

    private function address(): string
    {
        return self::ADDRESS.str_repeat("\x11", 28).str_repeat("\x22", 28);
    }

    private function body(int $fee = 170000): TransactionBody
    {
        return TransactionBodyBuilder::create()
            ->input(TransactionInput::of(str_repeat("\x33", 32), 0))
            ->output(TransactionOutput::create($this->address(), Value::lovelace(5_000_000)))
            ->fee($fee)
            ->build();
    }

    /**
     * An empty witness set is an empty map, not a map holding empty lists.
     */
    public function test_an_empty_witness_set_writes_no_fields(): void
    {
        $set = WitnessSet::of([]);

        $this->assertSame('a0', bin2hex(CborCodec::encode($set->toCbor())));
        $this->assertSame([], $set->fieldKeys());
        $this->assertSame([], $set->vkeyWitnesses());
    }

    /**
     * Fields are written in ascending order with the empty ones left out.
     */
    public function test_fields_are_written_in_ascending_order(): void
    {
        $key = SigningKey::generate();
        $script = NativeScript::sig($key->credential()->hex());

        $witness = TransactionSigner::witness($this->body(), $key);

        $this->assertSame([0], WitnessSet::of([$witness])->fieldKeys());
        $this->assertSame([1], WitnessSet::of([], [$script])->fieldKeys());
        $this->assertSame([0, 1], WitnessSet::of([$witness], [$script])->fieldKeys());

        $key->discard();
    }

    /**
     * A native script handed in as a model and the same script handed in as raw CBOR produce the same bytes.
     */
    public function test_a_script_can_be_given_as_a_model_or_as_its_own_cbor(): void
    {
        $script = NativeScript::all(
            NativeScript::sig(str_repeat('ab', 28)),
            NativeScript::after(84_600_000),
        );

        $fromModel = WitnessSet::of([], [$script]);
        $fromCbor = WitnessSet::of([], [CborCodec::decode($script->cbor())]);

        $this->assertSame(
            bin2hex(CborCodec::encode($fromModel->toCbor())),
            bin2hex(CborCodec::encode($fromCbor->toCbor()))
        );

        $this->assertSame([$script->hashHex()], array_map(
            static fn (string $hash): string => bin2hex($hash),
            $fromModel->nativeScriptHashes()
        ));
    }

    /**
     * The number of measuring witnesses is the number of bytes the fee was worked out against.
     *
     * WitnessPlan says a vkey witness is 101 bytes and nothing else, and the assembled set has to be exactly that
     * many plus the array header plus the map around it. If the two ever disagreed, every fee this package computes
     * would be wrong by a multiple of 101 bytes and the node would be the thing that said so.
     */
    public function test_the_assembled_set_is_the_size_the_witness_plan_says(): void
    {
        foreach ([1, 2, 3, 23, 24, 100] as $count) {
            $plan = WitnessPlan::forSignatures($count);
            $set = WitnessSet::of($plan->dummyWitnesses());

            $this->assertCount($count, $set->vkeyWitnesses(), $count.' witnesses did not survive assembly.');

            // The map header is one byte for a single-field map, the key another, and the plan covers the rest.
            $this->assertSame(
                2 + $plan->vkeyFieldBytes(),
                strlen(CborCodec::encode($set->toCbor())),
                $count.' witnesses did not come to the size the plan says.'
            );
        }
    }

    /**
     * Identical measuring witnesses are all written, because what is being measured is how many there will be.
     *
     * Two dummies are the same 101 bytes, and a set that collapsed them would measure a transaction one signature
     * smaller than the one that gets submitted. What stops a real transaction going out with a repeated witness is
     * TransactionSigner refusing the same key twice, which is the assertion below.
     */
    public function test_identical_measuring_witnesses_are_not_collapsed(): void
    {
        $set = WitnessSet::of(WitnessPlan::forSignatures(3)->dummyWitnesses());

        $this->assertCount(3, $set->vkeyWitnesses());

        foreach ($set->vkeyWitnesses() as $witness) {
            $this->assertFalse(
                $witness->verifies(str_repeat("\x00", 32)),
                'A measuring witness verified, which means it is not a measuring witness.'
            );
        }
    }

    /**
     * Two parties signing in turn end up with both signatures on the transaction.
     *
     * This is the whole of what a multi-signature branch needs, and it is the case that replacing the witness set
     * rather than adding to it got wrong: the second signer handed back a transaction carrying only their own
     * witness, which a node refuses, and nothing said a signature had been dropped.
     */
    public function test_a_second_signer_does_not_drop_the_first_signature(): void
    {
        $first = SigningKey::generate();
        $second = SigningKey::generate();
        $body = $this->body();

        $unsigned = Transaction::assemble($body, WitnessSet::of(WitnessPlan::forSignatures(2)->dummyWitnesses()));

        $signedOnce = TransactionSigner::sign($unsigned, $first);
        $this->assertCount(1, $signedOnce->witnessSet->vkeyWitnesses());

        $signedTwice = TransactionSigner::sign($signedOnce, $second);

        $keys = array_map(
            static fn (VkeyWitness $witness): string => $witness->vkeyHex(),
            $signedTwice->witnessSet->vkeyWitnesses()
        );

        $this->assertCount(2, $keys, 'A second signature replaced the first instead of joining it.');
        $this->assertContains($first->publicKeyHex(), $keys, 'The first signature was dropped by the second signer.');
        $this->assertContains($second->publicKeyHex(), $keys);

        $this->assertTrue(
            TransactionSigner::witnessesVerify($signedTwice),
            'A witness on the twice-signed transaction does not verify against its body.'
        );
        $this->assertSame($unsigned->hashHex(), $signedTwice->hashHex(), 'Signing moved the transaction hash.');
    }

    /**
     * A key that has already witnessed the transaction is refused rather than witnessing it again.
     */
    public function test_a_key_that_already_witnessed_the_transaction_is_refused(): void
    {
        $key = SigningKey::generate();
        $unsigned = Transaction::assemble(
            $this->body(),
            WitnessSet::of(WitnessPlan::forSignatures(1)->dummyWitnesses())
        );

        $signed = TransactionSigner::sign($unsigned, $key);

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('has already witnessed this transaction');

        TransactionSigner::sign($signed, $key);
    }

    /**
     * Swapping the witnesses on a set whose fields arrived out of ascending order leaves the order alone.
     *
     * This is the way a value the model could not write back was reaching fromCbor from inside the package. The
     * ledger takes a witness set with its fields in any order, and swapping measuring witnesses for real ones used
     * to decide where the vkey field went as it walked the map: a field with a higher key came first, so the vkey
     * witnesses were written there and then again at their own key. The set that came back had a different field
     * order from the one that went in, and the map it was built from wrote one key twice.
     */
    public function test_swapping_witnesses_keeps_the_field_order_the_set_arrived_in(): void
    {
        $vkey = str_repeat("\x01", 32);
        $signature = str_repeat("\x02", 64);

        // A witness set writing the native scripts field before the vkey witnesses field.
        $bytes = "\xa2"
            ."\x01"."\x80"
            ."\x00"."\x81"."\x82"."\x58\x20".$vkey."\x58\x40".$signature;

        $set = WitnessSet::fromCbor(CborCodec::decode($bytes));

        $this->assertSame([1, 0], $set->fieldKeys());
        $this->assertSame(bin2hex($bytes), bin2hex(CborCodec::encode($set->toCbor())));

        $swapped = $set->withVkeyWitnesses([
            VkeyWitness::of(str_repeat("\x03", 32), str_repeat("\x04", 64)),
        ]);

        $this->assertSame([1, 0], $swapped->fieldKeys(), 'Swapping the witnesses reordered the witness set.');
        $this->assertCount(1, $swapped->vkeyWitnesses());
        $this->assertSame(
            strlen($bytes),
            strlen(CborCodec::encode($swapped->toCbor())),
            'The swapped set is a different length from the set it replaced.'
        );
    }

    /**
     * And a set with no vkey witnesses at all gets them in ascending key order.
     */
    public function test_a_set_with_no_witnesses_gets_them_in_ascending_key_order(): void
    {
        $set = WitnessSet::fromCbor(CborCodec::decode("\xa1\x01\x80"));

        $this->assertSame([1], $set->fieldKeys());

        $swapped = $set->withVkeyWitnesses([
            VkeyWitness::of(str_repeat("\x03", 32), str_repeat("\x04", 64)),
        ]);

        $this->assertSame([0, 1], $swapped->fieldKeys());
    }

    /**
     * Swapping measuring witnesses for real ones changes the witness set and nothing else.
     */
    public function test_signing_replaces_the_dummies_and_leaves_the_body_alone(): void
    {
        $key = SigningKey::generate();
        $body = $this->body();

        $unsigned = Transaction::assemble($body, WitnessSet::of(WitnessPlan::forSignatures(1)->dummyWitnesses()));
        $signed = TransactionSigner::sign($unsigned, $key);

        $this->assertSame($unsigned->hashHex(), $signed->hashHex(), 'Signing moved the transaction hash.');
        $this->assertSame(
            bin2hex($unsigned->body->encode()),
            bin2hex($signed->body->encode()),
            'Signing rewrote the body.'
        );
        $this->assertSame(
            strlen($unsigned->encode()),
            strlen($signed->encode()),
            'The signed transaction is not the size the unsigned one was measured at.'
        );

        $this->assertTrue(TransactionSigner::witnessesVerify($signed));
        $this->assertSame($key->publicKeyHex(), $signed->witnessSet->vkeyWitnesses()[0]->vkeyHex());

        $key->discard();
    }

    /**
     * A witness verifies against the body hash and against nothing else.
     *
     * This is the assertion that says what is signed. A witness made over the body bytes rather than their hash
     * would pass any test that only ever checked it against itself; here it is checked against the hash, against the
     * bytes, and against a body one lovelace different.
     */
    public function test_a_witness_covers_the_body_hash_and_not_the_body(): void
    {
        $key = SigningKey::generate();
        $body = $this->body();

        $witness = TransactionSigner::witness($body, $key);

        $this->assertTrue($witness->verifies($body->hash()));
        $this->assertFalse($witness->verifies(str_repeat("\x00", 32)));
        $this->assertFalse($witness->verifies($this->body(170001)->hash()));

        $key->discard();
    }

    /**
     * Handing the body bytes where a body hash is expected is refused rather than signed.
     */
    public function test_signing_something_that_is_not_a_body_hash_is_refused(): void
    {
        $key = SigningKey::generate();
        $body = $this->body();

        try {
            $this->expectException(SigningException::class);
            $this->expectExceptionMessage('A transaction hash is 32 bytes');

            TransactionSigner::witnessForHash($body->encode(), $key);
        } finally {
            $key->discard();
        }
    }

    public function test_signing_with_no_key_is_refused(): void
    {
        $unsigned = Transaction::assemble($this->body(), WitnessSet::of([]));

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage('at least one key');

        TransactionSigner::sign($unsigned);
    }

    /**
     * The same key twice is refused: it is not more signed, it is a hundred and one wasted bytes and a set with a
     * repeated member.
     */
    public function test_the_same_key_cannot_witness_twice(): void
    {
        $key = SigningKey::generate();
        $unsigned = Transaction::assemble($this->body(), WitnessSet::of([]));

        try {
            $this->expectException(SigningException::class);
            $this->expectExceptionMessage('was given twice');

            TransactionSigner::sign($unsigned, $key, $key);
        } finally {
            $key->discard();
        }
    }

    /**
     * Two different keys produce two witnesses, in the order they were given.
     */
    public function test_two_keys_produce_two_witnesses_in_order(): void
    {
        $first = SigningKey::generate();
        $second = SigningKey::generate();

        $unsigned = Transaction::assemble(
            $this->body(),
            WitnessSet::of(WitnessPlan::forSignatures(2)->dummyWitnesses())
        );

        $signed = TransactionSigner::sign($unsigned, $first, $second);

        $this->assertSame(
            [$first->publicKeyHex(), $second->publicKeyHex()],
            array_map(
                static fn (VkeyWitness $witness): string => $witness->vkeyHex(),
                $signed->witnessSet->vkeyWitnesses()
            )
        );

        $this->assertTrue(TransactionSigner::witnessesVerify($signed));

        $first->discard();
        $second->discard();
    }

    /**
     * Swapping the witnesses keeps the native scripts, because a script spend needs both.
     */
    public function test_signing_keeps_the_native_script_beside_the_signatures(): void
    {
        $key = SigningKey::generate();
        $script = NativeScript::any(
            NativeScript::sig($key->credential()->hex()),
            NativeScript::after(84_600_000),
        );

        $unsigned = Transaction::assemble(
            $this->body(),
            WitnessSet::of(WitnessPlan::forSignatures(1)->dummyWitnesses(), [$script])
        );

        $signed = TransactionSigner::sign($unsigned, $key);

        $this->assertSame(
            [$script->cborHex()],
            array_map(static fn (string $b): string => bin2hex($b), $signed->witnessSet->nativeScriptBytes()),
            'The script did not survive signing.'
        );

        $this->assertSame([0, 1], $signed->witnessSet->fieldKeys());

        $key->discard();
    }

    /**
     * A witness set field this package does not model survives the swap untouched.
     *
     * A set may carry Plutus data, redeemers or bootstrap witnesses. None of them is in this step's scope and none
     * of them may be dropped: the script data hash in the body covers the redeemers and the datums, so losing one on
     * the way through would produce a transaction that is correctly signed and refused for not matching its own
     * hash. Field 4, plutus_data, stands in for all of them here.
     */
    public function test_an_unmodelled_witness_field_survives_the_swap(): void
    {
        $plutusData = SequenceForm::definite()->wrap([CborValue::byteString(str_repeat("\x99", 16))]);

        $original = WitnessSet::fromCbor(MapForm::definite()->wrap([
            [CborInteger::of(WitnessSet::FIELD_VKEY_WITNESSES)->toCbor(), SequenceForm::definite()->wrap(
                array_map(static fn (VkeyWitness $w) => $w->toCbor(), WitnessPlan::forSignatures(1)->dummyWitnesses())
            )],
            [CborInteger::of(WitnessSet::FIELD_PLUTUS_DATA)->toCbor(), $plutusData],
        ]));

        $key = SigningKey::generate();
        $witness = TransactionSigner::witness($this->body(), $key);

        $swapped = $original->withVkeyWitnesses([$witness]);

        $this->assertSame([0, 4], $swapped->fieldKeys());
        $this->assertTrue($swapped->has(WitnessSet::FIELD_PLUTUS_DATA));
        $this->assertStringContainsString(
            bin2hex(CborCodec::encode($plutusData)),
            bin2hex(CborCodec::encode($swapped->toCbor())),
            'The plutus data field did not survive the witness swap.'
        );
        $this->assertSame(
            $witness->vkeyHex(),
            $swapped->vkeyWitnesses()[0]->vkeyHex(),
            'The witness swap did not put the real witness in.'
        );

        $key->discard();
    }

    /**
     * A witness set that had no vkey field gains one in ascending position rather than at the end.
     */
    public function test_a_set_with_no_vkey_field_gains_one_in_the_right_place(): void
    {
        $script = NativeScript::sig(str_repeat('cd', 28));
        $key = SigningKey::generate();

        $swapped = WitnessSet::of([], [$script])
            ->withVkeyWitnesses([TransactionSigner::witness($this->body(), $key)]);

        $this->assertSame([0, 1], $swapped->fieldKeys());
        $this->assertCount(1, $swapped->vkeyWitnesses());
        $this->assertCount(1, $swapped->nativeScripts());

        $key->discard();
    }

    /**
     * Build, measure, settle the fee, rebuild, sign, and the transaction that comes out pays for itself.
     *
     * This is the whole order in one test, and the closing assertion is the one a node makes: the fee written into
     * the body is at least the minimum fee for the number of bytes actually submitted. It is run against the
     * protocol parameters committed with the corpus rather than numbers typed in here.
     */
    public function test_a_transaction_built_measured_and_signed_pays_for_itself(): void
    {
        $parameters = LedgerFixtures::parameters();
        $key = SigningKey::generate();
        $plan = WitnessPlan::forSignatures(1);

        $render = fn (Natural $fee): string => Transaction::assemble(
            TransactionBodyBuilder::create()
                ->input(TransactionInput::of(str_repeat("\x33", 32), 0))
                ->output(TransactionOutput::create($this->address(), Value::lovelace(5_000_000)))
                ->output(TransactionOutput::create($this->address(), Value::lovelace(44_000_000)))
                ->ttl(84_600_000)
                ->fee($fee->value)
                ->build(),
            WitnessSet::of($plan->dummyWitnesses())
        )->encode();

        $solution = FeeFixedPoint::under($parameters)->solve($render);

        $unsigned = TransactionDecoder::decode($render($solution->fee));
        $signed = TransactionSigner::sign($unsigned, $key);

        $this->assertSame(
            $solution->transactionBytes,
            strlen($signed->encode()),
            'The signed transaction is not the size the fee was settled against.'
        );

        $required = FeeCalculator::under($parameters)->forBytes($signed->encode());

        $this->assertTrue(
            $signed->body->fee()->value === $solution->fee->value,
            'The fee in the body is not the fee that was settled.'
        );

        $this->assertTrue(
            Natural::of($signed->body->fee()->value)->isAtLeast($required),
            sprintf(
                'The transaction carries %s lovelace and a transaction of %d bytes needs %s.',
                $signed->body->fee()->value,
                strlen($signed->encode()),
                $required->value
            )
        );

        $this->assertTrue(TransactionSigner::witnessesVerify($signed));

        $key->discard();
    }

    /**
     * The padded fee path settles in one pass and produces a transaction of the size it was measured at.
     */
    public function test_the_padded_fee_path_also_produces_a_transaction_that_pays_for_itself(): void
    {
        $parameters = LedgerFixtures::parameters();
        $key = SigningKey::generate();
        $plan = WitnessPlan::forSignatures(1);

        $render = fn (Natural $fee): string => Transaction::assemble(
            TransactionBodyBuilder::create()
                ->input(TransactionInput::of(str_repeat("\x44", 32), 1))
                ->output(TransactionOutput::create($this->address(), Value::lovelace(2_000_000)))
                ->feeAtFullWidth($fee->value)
                ->build(),
            WitnessSet::of($plan->dummyWitnesses())
        )->encode();

        $solution = FeeFixedPoint::under($parameters)->padded($render);

        $this->assertTrue($solution->padded);
        $this->assertSame(1, $solution->passes);

        $signed = TransactionSigner::sign(TransactionDecoder::decode($render($solution->fee)), $key);

        $this->assertSame($solution->transactionBytes, strlen($signed->encode()));
        $this->assertTrue(
            Natural::of($signed->body->fee()->value)
                ->isAtLeast(FeeCalculator::under($parameters)->forBytes($signed->encode()))
        );

        $key->discard();
    }

    /**
     * A body with no inputs, no outputs or no fee is refused at build time.
     */
    public function test_the_builder_refuses_a_body_that_is_not_one(): void
    {
        $output = TransactionOutput::create($this->address(), Value::lovelace(1_000_000));
        $input = TransactionInput::of(str_repeat("\x55", 32), 0);

        $this->assertRefused(
            'at least one input',
            static fn () => TransactionBodyBuilder::create()->output($output)->fee(1)->build()
        );

        $this->assertRefused(
            'needs at least one output',
            static fn () => TransactionBodyBuilder::create()->input($input)->fee(1)->build()
        );

        $this->assertRefused(
            'needs a fee',
            static fn () => TransactionBodyBuilder::create()->input($input)->output($output)->build()
        );
    }

    /**
     * Values that are the wrong length or the wrong kind are refused, with hex singled out.
     *
     * Every identifier in this package is raw bytes, and a hex string is exactly twice the length of the bytes it
     * spells, so passing one is the mistake that produces a well formed transaction pointing at nothing.
     */
    public function test_identifiers_are_refused_when_they_are_hex_or_the_wrong_length(): void
    {
        $this->assertRefused(
            'A transaction id is 32 bytes, got 64. Pass raw bytes, not hex.',
            static fn () => TransactionInput::of(str_repeat('33', 32), 0)
        );

        $this->assertRefused(
            'An output index cannot be -1.',
            static fn () => TransactionInput::of(str_repeat("\x33", 32), -1)
        );

        $this->assertRefused(
            'A required signer is a 28 byte key hash',
            fn () => TransactionBodyBuilder::create()->requiredSigners([str_repeat('ab', 28)])
        );

        $this->assertRefused(
            'An auxiliary data hash is 32 bytes',
            fn () => TransactionBodyBuilder::create()->auxiliaryDataHash(str_repeat('ab', 32))
        );

        $this->assertRefused(
            'A network id is 0 or 1, got 2.',
            fn () => TransactionBodyBuilder::create()->networkId(2)
        );
    }

    private function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
        } catch (DecodeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail('Expected a refusal containing: '.$message);
    }
}
