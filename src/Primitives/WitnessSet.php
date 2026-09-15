<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Hash\Blake2b;
use CBOR\CBORObject;

/**
 * The witnesses attached to a transaction.
 *
 * Two fields are read: the vkey witnesses, because assertion three needs the key and the signature apart from one
 * another, and the native scripts, whose bytes a later step hashes into a script credential. Everything else in the
 * set -- bootstrap witnesses, Plutus scripts, datums, redeemers -- is carried through as the item it decoded to.
 * None of it is in this step's scope, and none of it may be lost, because the witness set is part of what gets
 * submitted even though it is not part of what gets hashed.
 */
final class WitnessSet
{
    public const FIELD_VKEY_WITNESSES = 0;

    public const FIELD_NATIVE_SCRIPTS = 1;

    public const FIELD_BOOTSTRAP_WITNESSES = 2;

    public const FIELD_PLUTUS_V1_SCRIPTS = 3;

    public const FIELD_PLUTUS_DATA = 4;

    public const FIELD_REDEEMERS = 5;

    public const FIELD_PLUTUS_V2_SCRIPTS = 6;

    public const FIELD_PLUTUS_V3_SCRIPTS = 7;

    private const FIELDS = [0, 1, 2, 3, 4, 5, 6, 7];

    /**
     * @param  array<int, CBORObject>  $fields
     * @param  array<int, CborInteger>  $keys
     * @param  list<VkeyWitness>  $vkeyWitnesses
     * @param  list<CBORObject>  $nativeScripts
     */
    private function __construct(
        private readonly MapForm $form,
        private readonly array $fields,
        private readonly array $keys,
        private readonly array $vkeyWitnesses,
        private readonly ?SequenceForm $vkeyForm,
        private readonly array $nativeScripts,
        private readonly ?SequenceForm $nativeScriptForm,
    ) {}

    public static function fromCbor(CBORObject $object, string $context = 'witness set'): self
    {
        [$form, $fields, $keys] = MapForm::unwrapIntKeyed($object, $context, self::FIELDS);

        $vkeyWitnesses = [];
        $vkeyForm = null;
        if (isset($fields[self::FIELD_VKEY_WITNESSES])) {
            [$vkeyForm, $items] = SequenceForm::unwrap(
                $fields[self::FIELD_VKEY_WITNESSES],
                $context.' vkey witnesses'
            );
            foreach ($items as $index => $item) {
                $vkeyWitnesses[] = VkeyWitness::fromCbor($item, sprintf('%s vkey witness %d', $context, $index));
            }
        }

        $nativeScripts = [];
        $nativeScriptForm = null;
        if (isset($fields[self::FIELD_NATIVE_SCRIPTS])) {
            [$nativeScriptForm, $nativeScripts] = SequenceForm::unwrap(
                $fields[self::FIELD_NATIVE_SCRIPTS],
                $context.' native scripts'
            );
        }

        return new self($form, $fields, $keys, $vkeyWitnesses, $vkeyForm, $nativeScripts, $nativeScriptForm);
    }

    public function toCbor(): CBORObject
    {
        $entries = [];
        foreach ($this->fields as $key => $field) {
            $entries[] = [$this->keys[$key]->toCbor(), match ($key) {
                self::FIELD_VKEY_WITNESSES => $this->vkeyForm?->wrap(
                    array_map(static fn (VkeyWitness $w): CBORObject => $w->toCbor(), $this->vkeyWitnesses)
                ) ?? $field,
                self::FIELD_NATIVE_SCRIPTS => $this->nativeScriptForm?->wrap($this->nativeScripts) ?? $field,
                default => $field,
            }];
        }

        return $this->form->wrap($entries);
    }

    /**
     * @return list<VkeyWitness>
     */
    public function vkeyWitnesses(): array
    {
        return $this->vkeyWitnesses;
    }

    /**
     * @return list<CBORObject>
     */
    public function nativeScripts(): array
    {
        return $this->nativeScripts;
    }

    /**
     * The encoded bytes of each native script, which is what a script hash is taken over.
     *
     * @return list<string>
     */
    public function nativeScriptBytes(): array
    {
        return array_map(
            static fn (CBORObject $script): string => CborCodec::encode($script),
            $this->nativeScripts
        );
    }

    /**
     * The script hash of each native script: blake2b-224 over a zero byte followed by the script's CBOR.
     *
     * This is what tells a minting policy from a spend without asking a provider anything. A native script whose hash
     * is among the policy ids in the mint field is witnessing that mint; one whose hash is not, in a transaction with
     * no certificates and no withdrawals, is witnessing a spend.
     *
     * @return list<string>
     */
    public function nativeScriptHashes(): array
    {
        return array_map(
            static fn (string $script): string => Blake2b::hash224("\x00".$script),
            $this->nativeScriptBytes()
        );
    }

    public function has(int $field): bool
    {
        return isset($this->fields[$field]);
    }

    /**
     * @return list<int>
     */
    public function fieldKeys(): array
    {
        return array_keys($this->fields);
    }
}
