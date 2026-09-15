<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Hash\Blake2b;

/**
 * A reward address: a stake credential under its own header, twenty-nine bytes in all.
 *
 * Rewards are paid to one of these, and a withdrawal names one. It is written with the `stake` prefix rather than
 * `addr`, which is the only outward sign that sending funds to it is not a thing that can be done.
 */
final class RewardAddress extends Address
{
    private function __construct(
        Network $network,
        public readonly Credential $stake,
    ) {
        parent::__construct($network);
    }

    public static function of(Network $network, Credential $stake): self
    {
        return new self($network, $stake);
    }

    public function type(): int
    {
        return self::TYPE_REWARD_KEY | ($this->stake->isScript() ? 1 : 0);
    }

    public function payload(): string
    {
        return $this->stake->hash;
    }

    public function hrp(): string
    {
        return $this->network->rewardHrp();
    }

    public function credential(): Credential
    {
        return $this->stake;
    }

    public static function fromPayload(Network $network, int $type, string $payload): self
    {
        self::exactly($payload, Blake2b::DIGEST_CREDENTIAL, 'A reward address');

        return new self(
            $network,
            ($type & 1) === 1 ? Credential::scriptHashBytes($payload) : Credential::keyHashBytes($payload),
        );
    }
}
