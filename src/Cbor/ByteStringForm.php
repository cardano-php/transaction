<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;

/**
 * How a byte string was written, kept apart from the bytes it held.
 *
 * All a definite length byte string's head states is how many bytes follow it, and CBOR states that five ways. The
 * payload is the same either way and so is what the field means, but the bytes are not, and the ledger hashes the
 * bytes it was handed. A model that kept only the payload writes every byte string back at the narrowest head, which
 * is what the chain happens to use everywhere and is not something a decoder may assume: a transaction arriving from
 * a partner, a wallet or a hand written encoder can carry a wider one, and rewriting it moves the transaction hash
 * under a transaction nobody edited.
 *
 * Every position the transaction model takes apart and puts back together holds one of these beside the payload:
 * output addresses, input transaction ids, the auxiliary data and script data hashes, required signers, policy ids,
 * asset names, public keys and signatures. A value built rather than decoded has no arrived bytes to reproduce and
 * takes the narrowest head that holds it, which is what every other encoder in the ecosystem writes.
 *
 * The recorded head is reused only while it still describes what is being written. A byte string head states a
 * length, so a payload of a different length takes a fresh head instead; that cannot happen to a field whose length
 * is fixed, and it is what keeps this honest for the ones where it can.
 *
 * @internal This is the package's own reading machinery. It is held privately by the primitives that decode a byte
 * string and is named by no public signature, so nothing outside the package can be holding one, and it is not part
 * of what the package promises to keep working.
 */
final class ByteStringForm
{
    private function __construct(
        private readonly ?int $headAdditionalInformation,
        private readonly ?string $headArgument,
        private readonly ?int $headLength,
    ) {}

    /** The form a freshly built byte string is written in: the narrowest head that states its length. */
    public static function shortest(): self
    {
        return new self(null, null, null);
    }

    /**
     * The form a decoded definite length byte string arrived in.
     *
     * Every call site has already been through Shape::bytes, which is what refuses anything that is not a definite
     * length byte string, so anything else reaching here is a decoder that skipped that step rather than a document.
     */
    public static function of(CborValue $value): self
    {
        if (! $value->isByteString()) {
            throw new DecodeException(sprintf(
                'A byte string form is taken from a definite length byte string, got %s.',
                $value->describe()
            ));
        }

        return new self(
            $value->additionalInformation,
            $value->argument,
            strlen($value->definitePayload()),
        );
    }

    public function wrap(string $payload): CborValue
    {
        if ($this->headLength === null || $this->headLength !== strlen($payload)) {
            return CborValue::byteString($payload);
        }

        return CborValue::stringAs(
            CborHead::MAJOR_BYTE_STRING,
            (int) $this->headAdditionalInformation,
            $this->headArgument,
            $payload,
        );
    }
}
