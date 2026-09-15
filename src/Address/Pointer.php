<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

use Cardano\Transaction\Exception\AddressException;

/**
 * A chain pointer: the place on chain where a stake key was registered.
 *
 * Three coordinates, an absolute slot, a transaction index within that slot and a certificate index within that
 * transaction, stand in for a stake key hash and take about half the room. Each is written as a base-128 natural,
 * most significant group first, with the high bit set on every byte but the last.
 *
 * From the Conway era no new pointer address can be added to mainnet. They are here because they are an address type
 * that exists, and because a decoder that met one and guessed would be worse than one that reads it.
 */
final class Pointer
{
    private function __construct(
        public readonly int $slot,
        public readonly int $txIndex,
        public readonly int $certIndex,
    ) {}

    public static function at(int $slot, int $txIndex, int $certIndex): self
    {
        foreach (['slot' => $slot, 'transaction index' => $txIndex, 'certificate index' => $certIndex] as $what => $value) {
            if ($value < 0) {
                throw new AddressException(sprintf('A pointer %s cannot be negative, got: %d', $what, $value));
            }
        }

        return new self($slot, $txIndex, $certIndex);
    }

    public function toBytes(): string
    {
        return self::natural($this->slot).self::natural($this->txIndex).self::natural($this->certIndex);
    }

    /**
     * Read three naturals from the payload of a pointer address.
     *
     * The trailing bytes are the whole of the delegation part, so anything left over after the third coordinate is a
     * malformed address rather than a pointer with something appended.
     */
    public static function fromBytes(string $bytes): self
    {
        $offset = 0;
        $read = [];

        foreach (['slot', 'transaction index', 'certificate index'] as $what) {
            $read[] = self::readNatural($bytes, $offset, $what);
        }

        if ($offset !== strlen($bytes)) {
            throw new AddressException(sprintf(
                'A pointer is three variable length naturals, and %d byte(s) follow the third.',
                strlen($bytes) - $offset
            ));
        }

        return new self($read[0], $read[1], $read[2]);
    }

    /**
     * @return array{slot: int, tx_index: int, cert_index: int}
     */
    public function toArray(): array
    {
        return ['slot' => $this->slot, 'tx_index' => $this->txIndex, 'cert_index' => $this->certIndex];
    }

    private static function natural(int $value): string
    {
        $groups = [$value & 0x7F];
        $value >>= 7;

        while ($value > 0) {
            array_unshift($groups, ($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return implode('', array_map(chr(...), $groups));
    }

    private static function readNatural(string $bytes, int &$offset, string $what): int
    {
        $value = 0;

        while (true) {
            if ($offset >= strlen($bytes)) {
                throw new AddressException(sprintf('A pointer %s runs off the end of the address.', $what));
            }

            $byte = ord($bytes[$offset++]);

            // Seven bits per byte, so nine bytes is already more than a signed 64 bit integer can hold.
            if ($value > (PHP_INT_MAX >> 7)) {
                throw new AddressException(sprintf('A pointer %s is larger than this platform can hold.', $what));
            }

            $value = ($value << 7) | ($byte & 0x7F);

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
    }
}
