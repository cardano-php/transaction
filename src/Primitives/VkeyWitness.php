<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Primitives;

use Cardano\Transaction\Cbor\ByteStringForm;
use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Cbor\Shape;
use Cardano\Transaction\Exception\DecodeException;

/**
 * A public key and its signature over the body hash.
 *
 * The lengths are fixed at thirty-two and sixty-four bytes and are checked rather than assumed, because sodium
 * rejects the wrong length with an exception rather than a false, and a witness set carrying a short key would then
 * read as a crash instead of as a refusal.
 */
final class VkeyWitness
{
    private function __construct(
        public readonly string $vkey,
        public readonly string $signature,
        private readonly SequenceForm $form,
        private readonly ByteStringForm $vkeyForm,
        private readonly ByteStringForm $signatureForm,
    ) {}

    /**
     * A witness built rather than decoded.
     *
     * The lengths are held to here as well as on the way in, because a dummy witness built the wrong size would put
     * the whole fee calculation out by exactly as much as it was wrong, and silently.
     */
    public static function of(string $vkey, string $signature): self
    {
        if (strlen($vkey) !== 32) {
            throw new DecodeException(sprintf('A public key is 32 bytes, got %d.', strlen($vkey)));
        }

        if (strlen($signature) !== 64) {
            throw new DecodeException(sprintf('An Ed25519 signature is 64 bytes, got %d.', strlen($signature)));
        }

        return new self(
            $vkey,
            $signature,
            SequenceForm::definite(),
            ByteStringForm::shortest(),
            ByteStringForm::shortest(),
        );
    }

    public static function fromCbor(CborValue $object, string $context): self
    {
        [$form, $items] = SequenceForm::unwrap($object, $context, allowSetTag: false);

        if (count($items) !== 2) {
            throw new DecodeException(sprintf(
                '%s: expected a key and a signature, got %d item(s).',
                $context,
                count($items)
            ));
        }

        return new self(
            Shape::bytes($items[0], $context.' public key', 32),
            Shape::bytes($items[1], $context.' signature', 64),
            $form,
            ByteStringForm::of($items[0]),
            ByteStringForm::of($items[1]),
        );
    }

    public function toCbor(): CborValue
    {
        return $this->form->wrap([
            $this->vkeyForm->wrap($this->vkey),
            $this->signatureForm->wrap($this->signature),
        ]);
    }

    /** The witness as bytes. Its length is what WitnessPlan sizes a fee against. */
    public function encode(): string
    {
        return CborCodec::encode($this->toCbor());
    }

    public function verifies(string $bodyHash): bool
    {
        return sodium_crypto_sign_verify_detached($this->signature, $bodyHash, $this->vkey);
    }

    public function vkeyHex(): string
    {
        return bin2hex($this->vkey);
    }
}
