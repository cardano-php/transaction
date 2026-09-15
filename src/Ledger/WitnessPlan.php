<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Ledger;

use Cardano\Transaction\Exception\ArithmeticException;
use Cardano\Transaction\Primitives\VkeyWitness;

/**
 * How many bytes the witnesses will take, worked out before any of them exist.
 *
 * The fee is charged on the witnessed transaction, and the transaction cannot be witnessed until the fee is settled,
 * because the fee is inside the body the signatures are over. The way out is that a vkey witness has no variable
 * part. A public key is thirty-two bytes and an Ed25519 signature is sixty-four, always, so the witness is a
 * definite two-item array of a 32-byte string and a 64-byte string: 1 + 2 + 32 + 2 + 64 = 101 bytes, with no case
 * where it is 100 or 102. A witness of zeroes is therefore the same size as the real one, and sizing the
 * transaction with dummies is exact rather than an estimate.
 *
 * For a 1-of-2 native script spend this is fully determined: one signature, and the script itself, whose bytes are
 * known at build time because the script is what the address was derived from. Nothing here has to guess.
 *
 * Scripts and anything else already in the witness set are counted as the bytes they actually are, through
 * additionalBytes, rather than modelled. They are not dummies; they are known.
 */
final class WitnessPlan
{
    /** A vkey witness, encoded. 0x82, then 0x5820 and 32 bytes, then 0x5840 and 64 bytes. */
    public const VKEY_WITNESS_BYTES = 101;

    private function __construct(
        public readonly int $signatureCount,
        public readonly int $additionalBytes,
    ) {}

    public static function forSignatures(int $count, int $additionalBytes = 0): self
    {
        if ($count < 0) {
            throw new ArithmeticException(sprintf('A transaction cannot carry %d signatures.', $count));
        }

        if ($additionalBytes < 0) {
            throw new ArithmeticException(sprintf('A witness set cannot carry %d bytes.', $additionalBytes));
        }

        return new self($count, $additionalBytes);
    }

    /** Signatures only, without the array that holds them. */
    public function signatureBytes(): int
    {
        return $this->signatureCount * self::VKEY_WITNESS_BYTES;
    }

    /** The signatures and the definite-length array header around them. */
    public function vkeyFieldBytes(): int
    {
        return $this->signatureCount === 0
            ? 0
            : self::listHeaderBytes($this->signatureCount) + $this->signatureBytes();
    }

    /** Everything the witness set contributes: the vkey field, plus whatever else is already known to be in it. */
    public function totalBytes(): int
    {
        return $this->vkeyFieldBytes() + $this->additionalBytes;
    }

    /**
     * Witnesses of the right size holding nothing.
     *
     * These are for measuring, never for submitting. A signature of zeroes does not verify, which is the property
     * that stops one reaching a node by accident: the assembly step replaces every one of them, and a transaction
     * that still carries one is refused by the ledger rather than accepted as unsigned.
     *
     * @return list<VkeyWitness>
     */
    public function dummyWitnesses(): array
    {
        $witnesses = [];
        for ($i = 0; $i < $this->signatureCount; $i++) {
            $witnesses[] = VkeyWitness::of(str_repeat("\x00", 32), str_repeat("\x00", 64));
        }

        return $witnesses;
    }

    /**
     * The bytes a definite-length CBOR array of this many items spends on its own header.
     */
    public static function listHeaderBytes(int $count): int
    {
        return match (true) {
            $count <= 23 => 1,
            $count <= 255 => 2,
            $count <= 65535 => 3,
            default => 5,
        };
    }
}
