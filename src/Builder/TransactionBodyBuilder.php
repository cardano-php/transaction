<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Builder;

use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Primitives\AuxiliaryData;
use Cardano\Transaction\Primitives\MultiAsset;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\TransactionInput;
use Cardano\Transaction\Primitives\TransactionOutput;
use CBOR\ByteStringObject;
use CBOR\CBORObject;

/**
 * Writes a transaction body.
 *
 * Every field is written in ascending key order in a definite-length map, and a field that was never set is left out
 * rather than written empty. That is not an attempt at canonical CBOR, which the ledger does not require: it is the
 * arrangement every other builder emits, so a transaction built here and the same transaction built by a reference
 * implementation come out as the same bytes and any difference between them is a real one rather than a layout
 * choice. Sets are written untagged, because tag 258 is optional in Conway and costs two bytes per set.
 *
 * **The body is assembled as CBOR and then read back through the decoder.** The decoder is the half of this package
 * that was proved against the chain, transaction by transaction, so routing everything built here through it means a
 * body this class emits is one the decoder accepts, hashes and round-trips, by construction. The alternative is a
 * second set of validation rules living in the builder, drifting away from the first set, and agreeing with it right
 * up until the day it matters.
 *
 * **What this class does not do.** It does not choose inputs, work out change, or compute a fee. Those are decided
 * before a body is written, and the fee in particular cannot be settled by anything that only sees the body, because
 * it is charged on the witnessed transaction. The order is: select, shape the outputs, build with a fee of nought
 * and witnesses of the right size holding zeroes, measure, settle the fee against the measurement, rebuild, sign.
 * FeeFixedPoint drives the middle of that and takes a callback that ends in a call to this class.
 */
final class TransactionBodyBuilder
{
    /** @var list<TransactionInput> */
    private array $inputs = [];

    /** @var list<TransactionOutput> */
    private array $outputs = [];

    private ?CborInteger $fee = null;

    private ?CborInteger $ttl = null;

    private ?CborInteger $validityIntervalStart = null;

    private ?string $auxiliaryDataHash = null;

    private ?MultiAsset $mint = null;

    /** @var list<string> */
    private array $requiredSigners = [];

    private ?int $networkId = null;

    /** @var list<TransactionInput> */
    private array $collateral = [];

    /** @var list<TransactionInput> */
    private array $referenceInputs = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self;
    }

    public function input(TransactionInput $input): self
    {
        $this->inputs[] = $input;

        return $this;
    }

    /**
     * @param  list<TransactionInput>  $inputs
     */
    public function inputs(array $inputs): self
    {
        $this->inputs = array_values($inputs);

        return $this;
    }

    public function output(TransactionOutput $output): self
    {
        $this->outputs[] = $output;

        return $this;
    }

    /**
     * @param  list<TransactionOutput>  $outputs
     */
    public function outputs(array $outputs): self
    {
        $this->outputs = array_values($outputs);

        return $this;
    }

    public function fee(int|string $lovelace): self
    {
        $this->fee = CborInteger::of($lovelace);

        return $this;
    }

    /**
     * The fee written at the full eight-byte width, so the field never changes size when the number in it does.
     *
     * This is the padded half of the fee fixed point. It costs up to seven bytes and turns the iteration into a
     * single pass; FeeFixedPoint::padded() is the caller that wants it, and it checks that the size really did hold
     * still rather than trusting that this method was used.
     */
    public function feeAtFullWidth(int|string $lovelace): self
    {
        $this->fee = CborInteger::widest($lovelace);

        return $this;
    }

    public function ttl(int|string $slot): self
    {
        $this->ttl = CborInteger::of($slot);

        return $this;
    }

    public function validityIntervalStart(int|string $slot): self
    {
        $this->validityIntervalStart = CborInteger::of($slot);

        return $this;
    }

    /**
     * The 32-byte blake2b-256 of the auxiliary data, as raw bytes.
     */
    public function auxiliaryDataHash(string $hash): self
    {
        if (strlen($hash) !== Blake2b::DIGEST_TRANSACTION) {
            throw new DecodeException(sprintf(
                'An auxiliary data hash is %d bytes, got %d. Pass raw bytes, not hex.',
                Blake2b::DIGEST_TRANSACTION,
                strlen($hash)
            ));
        }

        $this->auxiliaryDataHash = $hash;

        return $this;
    }

    /**
     * Attach auxiliary data by hashing it, rather than by being told what its hash is.
     *
     * The body carries the hash and the transaction carries the data, in two different places, and they have to
     * agree or the ledger refuses the transaction. Handing the data to the builder is the only arrangement where
     * they cannot drift: the same bytes are hashed here and attached at assembly.
     */
    public function auxiliaryData(AuxiliaryData $auxiliaryData): self
    {
        return $this->auxiliaryDataHash($auxiliaryData->hash());
    }

    public function mint(MultiAsset $mint): self
    {
        $this->mint = $mint;

        return $this;
    }

    /**
     * Key hashes, as raw 28-byte strings, that the ledger must see a signature from.
     *
     * @param  list<string>  $keyHashes
     */
    public function requiredSigners(array $keyHashes): self
    {
        foreach ($keyHashes as $keyHash) {
            if (strlen($keyHash) !== Blake2b::DIGEST_CREDENTIAL) {
                throw new DecodeException(sprintf(
                    'A required signer is a %d byte key hash, got %d. Pass raw bytes, not hex.',
                    Blake2b::DIGEST_CREDENTIAL,
                    strlen($keyHash)
                ));
            }
        }

        $this->requiredSigners = array_values($keyHashes);

        return $this;
    }

    public function networkId(int $networkId): self
    {
        if ($networkId !== 0 && $networkId !== 1) {
            throw new DecodeException(sprintf('A network id is 0 or 1, got %d.', $networkId));
        }

        $this->networkId = $networkId;

        return $this;
    }

    /**
     * @param  list<TransactionInput>  $inputs
     */
    public function collateral(array $inputs): self
    {
        $this->collateral = array_values($inputs);

        return $this;
    }

    /**
     * @param  list<TransactionInput>  $inputs
     */
    public function referenceInputs(array $inputs): self
    {
        $this->referenceInputs = array_values($inputs);

        return $this;
    }

    public function build(): TransactionBody
    {
        if ($this->inputs === []) {
            throw new DecodeException('A transaction body needs at least one input.');
        }

        if ($this->outputs === []) {
            throw new DecodeException('A transaction body needs at least one output.');
        }

        if ($this->fee === null) {
            throw new DecodeException('A transaction body needs a fee, even if it is nought while it is measured.');
        }

        $sequence = SequenceForm::definite();
        $fields = [];

        $fields[TransactionBody::FIELD_INPUTS] = $sequence->wrap(self::inputList($this->inputs));
        $fields[TransactionBody::FIELD_OUTPUTS] = $sequence->wrap(array_map(
            static fn (TransactionOutput $output): CBORObject => $output->toCbor(),
            $this->outputs
        ));
        $fields[TransactionBody::FIELD_FEE] = $this->fee->toCbor();

        if ($this->ttl !== null) {
            $fields[TransactionBody::FIELD_TTL] = $this->ttl->toCbor();
        }

        if ($this->auxiliaryDataHash !== null) {
            $fields[TransactionBody::FIELD_AUXILIARY_DATA_HASH] = ByteStringObject::create($this->auxiliaryDataHash);
        }

        if ($this->validityIntervalStart !== null) {
            $fields[TransactionBody::FIELD_VALIDITY_INTERVAL_START] = $this->validityIntervalStart->toCbor();
        }

        if ($this->mint !== null) {
            $fields[TransactionBody::FIELD_MINT] = $this->mint->toCbor();
        }

        if ($this->collateral !== []) {
            $fields[TransactionBody::FIELD_COLLATERAL] = $sequence->wrap(self::inputList($this->collateral));
        }

        if ($this->requiredSigners !== []) {
            $fields[TransactionBody::FIELD_REQUIRED_SIGNERS] = $sequence->wrap(array_map(
                static fn (string $keyHash): CBORObject => ByteStringObject::create($keyHash),
                $this->requiredSigners
            ));
        }

        if ($this->networkId !== null) {
            $fields[TransactionBody::FIELD_NETWORK_ID] = CborInteger::of($this->networkId)->toCbor();
        }

        if ($this->referenceInputs !== []) {
            $fields[TransactionBody::FIELD_REFERENCE_INPUTS] = $sequence->wrap(self::inputList($this->referenceInputs));
        }

        ksort($fields, SORT_NUMERIC);

        $entries = [];
        foreach ($fields as $key => $value) {
            $entries[] = [CborInteger::of($key)->toCbor(), $value];
        }

        return TransactionBody::fromCbor(MapForm::definite()->wrap($entries), 'assembled body');
    }

    /**
     * @param  list<TransactionInput>  $inputs
     * @return list<CBORObject>
     */
    private static function inputList(array $inputs): array
    {
        return array_map(
            static fn (TransactionInput $input): CBORObject => $input->toCbor(),
            $inputs
        );
    }
}
