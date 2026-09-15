<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;

/**
 * One asset class: a policy id and an asset name.
 *
 * Both halves are raw bytes, and both are needed. The policy id alone is a minting authority, not an asset, and two
 * different tokens under one policy are two different balances that never add together. An asset name may be empty
 * and may be up to thirty-two bytes, and it is arbitrary bytes rather than text: plenty of real asset names are not
 * valid UTF-8, so nothing here decodes one, and the hex spelling is what gets shown to a person.
 */
final class AssetId
{
    private function __construct(
        public readonly string $policyId,
        public readonly string $name,
    ) {}

    public static function of(string $policyId, string $name): self
    {
        if (strlen($policyId) !== 28) {
            throw new SelectionException(sprintf('A policy id is 28 bytes, got %d.', strlen($policyId)));
        }

        if (strlen($name) > 32) {
            throw new SelectionException(sprintf('An asset name is at most 32 bytes, got %d.', strlen($name)));
        }

        return new self($policyId, $name);
    }

    public static function fromHex(string $policyIdHex, string $nameHex = ''): self
    {
        $policy = @hex2bin($policyIdHex);
        $name = $nameHex === '' ? '' : @hex2bin($nameHex);

        if ($policy === false || $name === false) {
            throw new SelectionException('A policy id and an asset name are written in hexadecimal.');
        }

        return self::of($policy, $name);
    }

    /**
     * The key this asset is tallied under. Policy and name concatenated in hex, which is the same spelling the
     * chain's own subject identifier uses, so a key here and a subject elsewhere are the same string.
     */
    public function key(): string
    {
        return bin2hex($this->policyId).bin2hex($this->name);
    }

    public function equals(self $other): bool
    {
        return $this->policyId === $other->policyId && $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
