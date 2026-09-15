<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Exception\AddressException;

/**
 * The network tag, which is the low four bits of an address header byte.
 *
 * CIP-19 defines two values and reserves the rest: nought is every test network and one is mainnet. There is no tag
 * that distinguishes preprod from preview, so the two produce identical bytes for identical credentials, and the only
 * thing separating them is which chain the address is used on.
 *
 * The same four bits also sit in a transaction body's network id field, so a body built for the wrong network is
 * refusable before it is submitted rather than after it is rejected.
 */
enum Network: int
{
    case Testnet = 0;

    case Mainnet = 1;

    /**
     * The tag as it appears in a header byte or a body's network id field.
     */
    public static function fromId(int $id): self
    {
        return self::tryFrom($id) ?? throw new AddressException(
            'A network tag is 0 for a test network or 1 for mainnet, got: '.$id
        );
    }

    /**
     * The network by the name the application uses for it.
     *
     * Only the names someone has decided the application supports are accepted. A new network is a decision about
     * which chain money is sent to, so an unrecognised name is refused rather than assumed to be a test network on
     * the grounds that most of them are.
     */
    public static function named(string $name): self
    {
        return match ($name) {
            'mainnet' => self::Mainnet,
            'preprod', 'preview', 'testnet' => self::Testnet,
            default => throw new AddressException('Unknown network: '.$name),
        };
    }

    /**
     * The bech32 prefix for a payment address, from CIP-5.
     */
    public function paymentHrp(): string
    {
        return $this === self::Mainnet ? 'addr' : 'addr_test';
    }

    /**
     * The bech32 prefix for a reward address, from CIP-5.
     */
    public function rewardHrp(): string
    {
        return $this === self::Mainnet ? 'stake' : 'stake_test';
    }

    public function isMainnet(): bool
    {
        return $this === self::Mainnet;
    }
}
