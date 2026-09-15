<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Hash\Blake2b;

/**
 * A base address: a payment credential and a delegation credential, fifty-seven bytes in all.
 *
 * The payment part owns the funds and the delegation part owns the stake rights, and the two need not belong to the
 * same party. That is what makes a base address the right shape for a campaign whose funds sit behind a script while
 * the stake follows the wallet that connected: the script hash goes in the payment part and the connecting wallet's
 * stake credential in the delegation part.
 *
 * The address type is decided by the two credentials, not chosen: a script payment part sets bit 0 and a script
 * delegation part sets bit 1, which is exactly CIP-19's types 0 to 3.
 */
final class BaseAddress extends Address
{
    private const PAYLOAD = 2 * Blake2b::DIGEST_CREDENTIAL;

    private function __construct(
        Network $network,
        public readonly Credential $payment,
        public readonly Credential $delegation,
    ) {
        parent::__construct($network);
    }

    public static function of(Network $network, Credential $payment, Credential $delegation): self
    {
        return new self($network, $payment, $delegation);
    }

    public function type(): int
    {
        return ($this->payment->isScript() ? 1 : 0) | ($this->delegation->isScript() ? 2 : 0);
    }

    public function payload(): string
    {
        return $this->payment->hash.$this->delegation->hash;
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
     * The reward address the stake behind this address is paid to.
     */
    public function rewardAddress(): RewardAddress
    {
        return RewardAddress::of($this->network, $this->delegation);
    }

    public function enterpriseAddress(): EnterpriseAddress
    {
        return EnterpriseAddress::of($this->network, $this->payment);
    }

    public static function fromPayload(Network $network, int $type, string $payload): self
    {
        self::exactly($payload, self::PAYLOAD, 'A base address');

        $payment = substr($payload, 0, Blake2b::DIGEST_CREDENTIAL);
        $delegation = substr($payload, Blake2b::DIGEST_CREDENTIAL);

        return new self(
            $network,
            ($type & 1) === 1 ? Credential::scriptHashBytes($payment) : Credential::keyHashBytes($payment),
            ($type & 2) === 2 ? Credential::scriptHashBytes($delegation) : Credential::keyHashBytes($delegation),
        );
    }
}
