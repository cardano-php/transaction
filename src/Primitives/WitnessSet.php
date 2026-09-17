<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Script\NativeScript;

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
     * @param  array<int, CborValue>  $fields
     * @param  array<int, CborInteger>  $keys
     * @param  list<VkeyWitness>  $vkeyWitnesses
     * @param  list<CborValue>  $nativeScripts
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

    /**
     * A witness set built rather than decoded.
     *
     * It is assembled as CBOR and then read back through the decoder, so a set this method produces is one the
     * decoder accepts by construction rather than by inspection. That costs one encode and one decode per
     * transaction and removes a whole class of builder that emits something nothing else will read.
     *
     * Fields are written in ascending key order and an empty field is left out rather than written as an empty
     * array. A witness set carrying an empty vkey list is legal and is two wasted bytes that also read, to anything
     * scanning for unsigned transactions, as a deliberate claim that this one has no signatures.
     *
     * @param  list<VkeyWitness>  $vkeyWitnesses
     * @param  list<NativeScript|CborValue>  $nativeScripts
     */
    public static function of(array $vkeyWitnesses, array $nativeScripts = []): self
    {
        $form = SequenceForm::definite();
        $entries = [];

        if ($vkeyWitnesses !== []) {
            $entries[] = [
                CborInteger::of(self::FIELD_VKEY_WITNESSES)->toCbor(),
                $form->wrap(array_map(
                    static fn (VkeyWitness $witness): CborValue => $witness->toCbor(),
                    array_values($vkeyWitnesses)
                )),
            ];
        }

        if ($nativeScripts !== []) {
            $entries[] = [
                CborInteger::of(self::FIELD_NATIVE_SCRIPTS)->toCbor(),
                $form->wrap(array_map(
                    static fn (NativeScript|CborValue $script): CborValue => $script instanceof NativeScript
                        ? $script->toCbor()
                        : $script,
                    array_values($nativeScripts)
                )),
            ];
        }

        return self::fromCbor(MapForm::definite()->wrap($entries), 'assembled witness set');
    }

    /**
     * The same witness set carrying different vkey witnesses, with every other field untouched.
     *
     * This is the step that turns a measured transaction into a submittable one. A fee is charged on the witnessed
     * size, so the transaction is built with witnesses of the right length holding zeroes, measured, and only then
     * signed; this is where the zeroes are replaced. Every other field is rebuilt from what was decoded rather than
     * from a model of it, because a set may carry Plutus data or redeemers this package does not model and dropping
     * them here would produce a transaction that no longer matches its own script data hash.
     *
     * A set that already carries vkey witnesses keeps them where they were, at the key they were written with, so
     * the set that comes back differs from the one that went in by the witnesses and by nothing else. A set that
     * does not carries them in ascending key order beside its other fields, which is the order the chain writes.
     * Placing them anywhere else would reorder the map, and map order is part of the bytes that get submitted.
     *
     * @param  list<VkeyWitness>  $witnesses
     */
    public function withVkeyWitnesses(array $witnesses): self
    {
        $replacement = ($this->vkeyForm ?? SequenceForm::definite())->wrap(array_map(
            static fn (VkeyWitness $witness): CborValue => $witness->toCbor(),
            array_values($witnesses)
        ));

        // Where the vkey witnesses go when the set does not already carry them: before the first field with a
        // higher key, and at the end when there is none. Working this out first, rather than while writing the
        // entries out, is what keeps it from being written twice. A set whose fields arrived out of ascending order
        // has a field with a higher key before the position the vkey witnesses belong at, and a loop that decided
        // as it went would put them there and then again at their own key.
        $insertBefore = $this->has(self::FIELD_VKEY_WITNESSES) ? null : count($this->fields);
        $position = 0;

        foreach (array_keys($this->fields) as $key) {
            if ($insertBefore !== null && $key > self::FIELD_VKEY_WITNESSES) {
                $insertBefore = min($insertBefore, $position);
            }

            $position++;
        }

        $entries = [];
        $position = 0;

        foreach ($this->fields as $key => $field) {
            if ($position === $insertBefore) {
                $entries[] = [CborInteger::of(self::FIELD_VKEY_WITNESSES)->toCbor(), $replacement];
            }

            $entries[] = [$this->keys[$key]->toCbor(), match ($key) {
                self::FIELD_VKEY_WITNESSES => $replacement,
                self::FIELD_NATIVE_SCRIPTS => $this->nativeScriptForm?->wrap($this->nativeScripts) ?? $field,
                default => $field,
            }];

            $position++;
        }

        if ($insertBefore === count($this->fields)) {
            $entries[] = [CborInteger::of(self::FIELD_VKEY_WITNESSES)->toCbor(), $replacement];
        }

        return self::fromCbor($this->form->wrap($entries), 'witness set');
    }

    public static function fromCbor(CborValue $object, string $context = 'witness set'): self
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

    public function toCbor(): CborValue
    {
        $entries = [];
        foreach ($this->fields as $key => $field) {
            $entries[] = [$this->keys[$key]->toCbor(), match ($key) {
                self::FIELD_VKEY_WITNESSES => $this->vkeyForm?->wrap(
                    array_map(static fn (VkeyWitness $w): CborValue => $w->toCbor(), $this->vkeyWitnesses)
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
     * @return list<CborValue>
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
            static fn (CborValue $script): string => CborCodec::encode($script),
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
