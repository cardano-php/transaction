<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Primitives\AuxiliaryData;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\WitnessSet;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The check that a decoded transaction writes back out as the thing it was read from.
 *
 * A transaction hash is blake2b over the body's bytes, and this package rebuilds those bytes from a model rather
 * than slicing them out of the input. Everything the model takes apart records the head and the framing it arrived
 * in, so the rebuild reaches the same bytes for every encoding the chain writes and for the ones it does not. The
 * check is what covers whatever that does not: a shape the model reads but cannot put back the way it found it.
 *
 * The shape that reaches it is a map that writes one field number twice. The two entries are one field in the
 * model, because a field is addressed by its number, so the body goes back out an entry shorter than it came in and
 * hashes to a different arrangement of the same fields. A caller acting on that hash has signed, submitted or
 * reported something other than what they were handed, and nothing downstream can tell.
 *
 * It arrives as a value rather than as bytes. Read from bytes, a repeated key is refused by the CBOR layer, which is
 * RFC 8949 section 5.6 and is a separate refusal asserted below. But Transaction::fromCbor and
 * TransactionBody::fromCbor are public and take a value, and a value is also what Transaction::assemble and
 * WitnessSet::withVkeyWitnesses hand them, none of which has been past the CBOR layer's checks.
 */
class RoundTripGuardTest extends TestCase
{
    /**
     * A body whose map writes one field number twice is refused instead of hashed.
     *
     * The two entries are the same field written at two different key head widths, so nothing about the body's
     * meaning has changed and every one of its fields is one the model reads. What has changed is the entry count,
     * and the entry count is in the bytes the hash is taken over.
     */
    public function test_a_body_that_writes_one_field_twice_is_refused_rather_than_hashed(): void
    {
        $duplicated = self::bodyWithTheFeeWrittenTwice();

        $thrown = null;

        try {
            TransactionBody::fromCbor($duplicated);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            DecodeException::class,
            $thrown,
            'A body that writes one field twice was read and would have answered with a hash.'
        );

        $this->assertStringContainsString(
            'cannot write back',
            $thrown->getMessage(),
            'The body was refused by something other than the round trip check.'
        );
    }

    /**
     * And the hash it would have answered with belongs to a different transaction.
     *
     * This is the part that says why the refusal is worth having. The model collapses the two entries into one, so
     * what it would have hashed is the body with the repeat dropped, which is built here and hashed directly. The
     * bytes that were handed in hash to something else. Neither number is read out of the package's own model: one
     * is blake2b over the bytes of the value, the other blake2b over the bytes of the collapsed body.
     */
    public function test_the_hash_that_body_would_have_answered_with_is_not_the_hash_of_its_bytes(): void
    {
        $duplicated = self::bodyWithTheFeeWrittenTwice();
        $collapsed = self::body();

        $this->assertSame(
            count($collapsed->entries()) + 1,
            count($duplicated->entries()),
            'The duplicated body does not have one entry more than the collapsed one.'
        );

        $this->assertNotSame(
            bin2hex(Blake2b::hash256(CborCodec::encode($duplicated))),
            bin2hex(Blake2b::hash256(CborCodec::encode($collapsed))),
            'The two bodies hash to the same thing, so there is nothing for the check to protect.'
        );
    }

    /**
     * The body it was built from still reads, so the refusal is about the repeat and about nothing else.
     */
    public function test_the_body_it_was_built_from_still_decodes_and_hashes_to_what_the_chain_returned(): void
    {
        $fixture = TransactionFixtures::chainFixture('plain-ada-payment');

        $this->assertSame(
            $fixture['tx_hash'],
            TransactionBody::fromCbor(self::body())->hashHex()
        );
    }

    /**
     * A whole transaction carrying that body is refused at the transaction layer as well.
     */
    public function test_a_transaction_carrying_that_body_is_refused(): void
    {
        $thrown = null;

        try {
            Transaction::fromCbor(self::transactionWithTheFeeWrittenTwice());
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(DecodeException::class, $thrown, 'The transaction was read rather than refused.');
        $this->assertStringContainsString('cannot write back', $thrown->getMessage());
    }

    /**
     * The same document as bytes is refused a level lower, by the CBOR layer, for writing one key twice.
     *
     * The two refusals are independent and neither makes the other unnecessary. This one covers everything that
     * arrives as bytes and says exactly what is wrong; the round trip check covers everything that arrives as a
     * value, which is every transaction this package assembles as well as every caller who decodes the CBOR
     * themselves.
     */
    public function test_the_same_document_as_bytes_is_refused_by_the_cbor_layer(): void
    {
        $bytes = CborCodec::encode(self::transactionWithTheFeeWrittenTwice());

        $thrown = null;

        try {
            TransactionDecoder::decode($bytes);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(DecodeException::class, $thrown);
        $this->assertStringContainsString('the same key is written twice', $thrown->getMessage());
    }

    // ------------------------------------------------------------------ the documents

    private static function body(): CborValue
    {
        return self::transaction()->items()[0];
    }

    /**
     * A transaction whose witness set writes one field twice is refused by the transaction's own check.
     *
     * The body is untouched here, so the body's check passes and says nothing. What rebuilds differently is the
     * witness set, and the witness set is not hashed, which is exactly why this needs its own guard: the body hash
     * is right, so every corpus assertion about hashes still holds, and the transaction that goes back out is a
     * different byte string from the one that arrived. Re-broadcasting or counter-signing then sends bytes nobody
     * handed over.
     */
    public function test_a_transaction_whose_witness_set_writes_one_field_twice_is_refused(): void
    {
        $tampered = self::transactionWithAWitnessFieldWrittenTwice();

        $thrown = null;

        try {
            Transaction::fromCbor($tampered);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            DecodeException::class,
            $thrown,
            'A transaction whose witness set collapses on rebuild was accepted, so what goes back out is not what '
            .'came in.'
        );
        $this->assertStringContainsString('cannot write back', $thrown->getMessage());

        // The body is untouched, which is what makes this the transaction's own check rather than the body's:
        // TransactionBody::fromCbor accepts the very same body without complaint.
        $this->assertSame(
            Blake2b::hash256(CborCodec::encode(self::body())),
            TransactionBody::fromCbor(self::body())->hash(),
            'The body was changed, so this test is not holding the layer it claims to.'
        );
    }

    /**
     * Auxiliary data that writes one slot twice is refused rather than hashed.
     *
     * Body field 7 carries the hash of this data. A value that rebuilds an entry shorter hashes to a different
     * arrangement of the same labels, so the body would carry a hash for bytes nobody submitted and the transaction
     * would be refused by a node for a reason nothing in the package could explain.
     */
    public function test_auxiliary_data_that_writes_one_slot_twice_is_refused(): void
    {
        $duplicated = self::auxiliaryDataWithASlotWrittenTwice();

        $thrown = null;

        try {
            AuxiliaryData::fromCbor($duplicated);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            DecodeException::class,
            $thrown,
            'Auxiliary data that collapses on rebuild was accepted, so hash() would answer for other bytes.'
        );
        $this->assertStringContainsString('cannot write back', $thrown->getMessage());
    }

    /** The fixture's witness set with its vkey field written a second time, at a wider head for the key. */
    private static function transactionWithAWitnessFieldWrittenTwice(): CborValue
    {
        $transaction = self::transaction();
        $items = $transaction->items();

        $entries = $items[1]->entries();
        $vkeys = null;

        foreach ($entries as [$key, $value]) {
            if ($key->integerText() === (string) WitnessSet::FIELD_VKEY_WITNESSES) {
                $vkeys = $value;
            }
        }

        if ($vkeys === null) {
            throw new DecodeException('The fixture witness set carries no vkey witnesses to repeat.');
        }

        $entries[] = [CborValue::integerAs(false, 24, chr(WitnessSet::FIELD_VKEY_WITNESSES)), $vkeys];
        $items[1] = CborValue::map($entries);

        return CborValue::sequence($items);
    }

    /**
     * The Alonzo form with slot key 0 written a second time, at a wider head.
     *
     * A repeated label inside the 721 or 674 metadata map is held as a list of pairs and survives the rebuild
     * exactly, so it is not the shape that reaches this. The slot map above it is keyed, and two heads for the
     * number nought are one slot to it.
     */
    private static function auxiliaryDataWithASlotWrittenTwice(): CborValue
    {
        $metadata = CborCodec::decode(hex2bin('a11902a2a1636d7367816b4a756465206d6f76696e67'));

        return CborValue::tagged(259, CborValue::map([
            [CborValue::unsigned(0), $metadata],
            [CborValue::integerAs(false, 24, chr(0)), $metadata],
        ]));
    }

    private static function transaction(): CborValue
    {
        return CborCodec::decode(TransactionFixtures::bytes('chain/plain-ada-payment.hex'));
    }

    /**
     * The fixture's body with its fee field written a second time, at a wider head for the key.
     *
     * Two heads for one number is what makes this a repeat the model collapses rather than two fields: the model
     * addresses a field by the number its key holds, and both keys hold two.
     */
    private static function bodyWithTheFeeWrittenTwice(): CborValue
    {
        $body = self::body();
        $entries = $body->entries();
        $fee = null;

        foreach ($entries as [$key, $value]) {
            if ($key->integerText() === (string) TransactionBody::FIELD_FEE) {
                $fee = $value;
            }
        }

        if ($fee === null) {
            throw new DecodeException('The fixture body carries no fee to repeat.');
        }

        $entries[] = [CborValue::integerAs(false, 24, chr(TransactionBody::FIELD_FEE)), $fee];

        return CborValue::map($entries);
    }

    private static function transactionWithTheFeeWrittenTwice(): CborValue
    {
        $transaction = self::transaction();
        $items = $transaction->items();
        $items[0] = self::bodyWithTheFeeWrittenTwice();

        return CborValue::sequenceAs($transaction->additionalInformation, $transaction->argument, $items);
    }
}
