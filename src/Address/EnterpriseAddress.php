<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Hash\Blake2b;

/**
 * An enterprise address: a payment credential and nothing else, twenty-nine bytes in all.
 *
 * Funds here cannot be delegated, and that is the point rather than an oversight. It is the address to use when the
 * stake rights would otherwise sit with whoever happened to build the script, and it is what a campaign address is
 * until something decides where its stake should go.
 */
final class EnterpriseAddress extends Address
{
    private function __construct(
        Network $network,
        public readonly Credential $payment,
    ) {
        parent::__construct($network);
    }

    public static function of(Network $network, Credential $payment): self
    {
        return new self($network, $payment);
    }

    public function type(): int
    {
        return self::TYPE_ENTERPRISE_KEY | ($this->payment->isScript() ? 1 : 0);
    }

    public function payload(): string
    {
        return $this->payment->hash;
    }

    public function hrp(): string
    {
        return $this->network->paymentHrp();
    }

    public function credential(): Credential
    {
        return $this->payment;
    }

    /**
     * The same payment credential, delegating to the given stake credential.
     *
     * A different address for the same script, not a change to this one. Anything already sent to the enterprise
     * address stays there.
     */
    public function withDelegation(Credential $delegation): BaseAddress
    {
        return BaseAddress::of($this->network, $this->payment, $delegation);
    }

    public static function fromPayload(Network $network, int $type, string $payload): self
    {
        self::exactly($payload, Blake2b::DIGEST_CREDENTIAL, 'An enterprise address');

        return new self(
            $network,
            ($type & 1) === 1 ? Credential::scriptHashBytes($payload) : Credential::keyHashBytes($payload),
        );
    }
}
