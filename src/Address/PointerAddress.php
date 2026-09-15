<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Exception\AddressException;
use Cardano\Transaction\Hash\Blake2b;

/**
 * A pointer address: a payment credential, then three numbers naming the place on chain where a stake key was
 * registered.
 *
 * Its length is not fixed, because the three coordinates are written as variable length naturals.
 *
 * From the Conway era no new pointer address can be added to mainnet, so nothing here builds one on purpose. It
 * exists so that a pointer address arriving from a wallet or an old output is read as what it is rather than
 * mistaken for a truncated base address, whose first twenty-eight bytes it shares.
 */
final class PointerAddress extends Address
{
    private function __construct(
        Network $network,
        public readonly Credential $payment,
        public readonly Pointer $pointer,
    ) {
        parent::__construct($network);
    }

    public static function of(Network $network, Credential $payment, Pointer $pointer): self
    {
        return new self($network, $payment, $pointer);
    }

    public function type(): int
    {
        return self::TYPE_POINTER_KEY | ($this->payment->isScript() ? 1 : 0);
    }

    public function payload(): string
    {
        return $this->payment->hash.$this->pointer->toBytes();
    }

    public function hrp(): string
    {
        return $this->network->paymentHrp();
    }

    public function credential(): Credential
    {
        return $this->payment;
    }

    public static function fromPayload(Network $network, int $type, string $payload): self
    {
        if (strlen($payload) <= Blake2b::DIGEST_CREDENTIAL) {
            throw new AddressException(sprintf(
                'A pointer address carries a credential and three naturals after the header, got %d byte(s).',
                strlen($payload)
            ));
        }

        $credential = substr($payload, 0, Blake2b::DIGEST_CREDENTIAL);

        return new self(
            $network,
            ($type & 1) === 1 ? Credential::scriptHashBytes($credential) : Credential::keyHashBytes($credential),
            Pointer::fromBytes(substr($payload, Blake2b::DIGEST_CREDENTIAL)),
        );
    }
}
