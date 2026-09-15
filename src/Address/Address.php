<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Exception\AddressException;
use CardanoPhp\Bech32\Bech32;
use Throwable;

/**
 * A Shelley era address: one header byte, then a payload whose shape the header decides.
 *
 * The header's high four bits are the address type and its low four are the network tag. CIP-19 assigns eight payment
 * types and two stake types, and the type is not a label on the side: it is what tells a reader whether the
 * twenty-eight bytes that follow are a key hash or a script hash, and whether there is a delegation part after them.
 *
 * Type 8 is Byron, whose payload is a CBOR structure with nothing in common with the rest. It is recognised here and
 * refused, because reading its first twenty-eight bytes as a credential would produce a credential nobody holds.
 */
abstract class Address
{
    public const TYPE_BASE_KEY_KEY = 0;

    public const TYPE_BASE_SCRIPT_KEY = 1;

    public const TYPE_BASE_KEY_SCRIPT = 2;

    public const TYPE_BASE_SCRIPT_SCRIPT = 3;

    public const TYPE_POINTER_KEY = 4;

    public const TYPE_POINTER_SCRIPT = 5;

    public const TYPE_ENTERPRISE_KEY = 6;

    public const TYPE_ENTERPRISE_SCRIPT = 7;

    public const TYPE_BYRON = 8;

    public const TYPE_REWARD_KEY = 14;

    public const TYPE_REWARD_SCRIPT = 15;

    protected function __construct(public readonly Network $network) {}

    /** The address type, which is the high four bits of the header byte. */
    abstract public function type(): int;

    /** Everything after the header byte. */
    abstract public function payload(): string;

    /** The bech32 prefix this kind of address is written with. */
    abstract public function hrp(): string;

    final public function header(): int
    {
        return ($this->type() << 4) | $this->network->value;
    }

    final public function toBytes(): string
    {
        return chr($this->header()).$this->payload();
    }

    final public function toHex(): string
    {
        return bin2hex($this->toBytes());
    }

    /**
     * The address as a user sees it.
     *
     * Always lowercase. Bech32 permits an all uppercase spelling of the same bytes, but two spellings of one address
     * moving through a system is a reconciliation problem nobody needs, so only one is ever produced.
     */
    final public function toBech32(): string
    {
        try {
            return Bech32::encode($this->hrp(), Bech32::hexToByteArray($this->toHex()));
        } catch (Throwable $e) {
            throw new AddressException('Could not encode the address: '.$e->getMessage(), 0, $e);
        }
    }

    public function __toString(): string
    {
        return $this->toBech32();
    }

    /**
     * Read an address from the raw bytes an output or a provider carries.
     */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new AddressException('An address cannot be empty.');
        }

        $header = ord($bytes[0]);
        $type = $header >> 4;
        $payload = substr($bytes, 1);

        if ($type === self::TYPE_BYRON) {
            throw new AddressException(
                'This is a Byron address. Its payload is a CBOR structure rather than credentials, and it is written '
                .'in base58 rather than bech32.'
            );
        }

        $network = Network::fromId($header & 0x0F);

        return match ($type) {
            self::TYPE_BASE_KEY_KEY,
            self::TYPE_BASE_SCRIPT_KEY,
            self::TYPE_BASE_KEY_SCRIPT,
            self::TYPE_BASE_SCRIPT_SCRIPT => BaseAddress::fromPayload($network, $type, $payload),
            self::TYPE_POINTER_KEY,
            self::TYPE_POINTER_SCRIPT => PointerAddress::fromPayload($network, $type, $payload),
            self::TYPE_ENTERPRISE_KEY,
            self::TYPE_ENTERPRISE_SCRIPT => EnterpriseAddress::fromPayload($network, $type, $payload),
            self::TYPE_REWARD_KEY,
            self::TYPE_REWARD_SCRIPT => RewardAddress::fromPayload($network, $type, $payload),
            default => throw new AddressException('CIP-19 assigns no address type '.$type.'.'),
        };
    }

    public static function fromHex(string $hex): self
    {
        if (preg_match('/^(?:[0-9a-f]{2})+$/', $hex) !== 1) {
            throw new AddressException('An address in hex is an even number of lowercase hex characters, got: '.$hex);
        }

        return self::fromBytes((string) hex2bin($hex));
    }

    /**
     * Read an address a user or a wallet handed over.
     *
     * The prefix is checked against the header rather than trusted: a mainnet address relabelled `addr_test`, or a
     * reward address relabelled `addr`, decodes perfectly well and means something different from what it says.
     */
    public static function fromBech32(string $address): self
    {
        if ($address !== strtolower($address)) {
            throw new AddressException('An address is written in lowercase, got: '.$address);
        }

        try {
            [$hrp, $words] = Bech32::decode($address);
            $hex = Bech32::byteArrayToHex($words);
        } catch (Throwable $e) {
            throw new AddressException('Not a bech32 string: '.$e->getMessage(), 0, $e);
        }

        $parsed = self::fromHex($hex);

        if ($hrp !== $parsed->hrp()) {
            throw new AddressException(sprintf(
                'The prefix %s does not match the address header, which says %s.',
                $hrp,
                $parsed->hrp()
            ));
        }

        return $parsed;
    }

    /**
     * The payment credential of a payment address, or the stake credential of a reward address.
     *
     * Both sit immediately after the header, so this is the one field every address type has.
     */
    abstract public function credential(): Credential;

    /**
     * A payload has to be exactly the length its type says, with no room for a truncated or padded one.
     */
    protected static function exactly(string $payload, int $length, string $what): string
    {
        if (strlen($payload) !== $length) {
            throw new AddressException(sprintf(
                '%s carries %d byte(s) after the header, got %d.',
                $what,
                $length,
                strlen($payload)
            ));
        }

        return $payload;
    }
}
