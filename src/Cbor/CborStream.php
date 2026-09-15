<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use CBOR\Stream;

/**
 * The byte source the CBOR decoder reads from.
 *
 * The library ships a StringStream that does the same reading, but it cannot say how much of the input is left. That
 * answer is the whole difference between "this is a transaction" and "this begins with a transaction": a decoder that
 * never asks accepts any number of bytes appended to a valid one.
 *
 * @internal This is the package's own reading machinery. It is handed to the CBOR library's decoder and to nothing
 * else, it is named by no public signature, and it is not part of what the package promises to keep working.
 */
final class CborStream implements Stream
{
    private int $offset = 0;

    private readonly int $length;

    public function __construct(
        private readonly string $data
    ) {
        $this->length = strlen($data);
    }

    public function read(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $available = $this->length - $this->offset;
        if ($available < $length) {
            throw new DecodeException(sprintf(
                'Truncated input: %d byte(s) wanted at offset %d, %d available.',
                $length,
                $this->offset,
                max($available, 0)
            ));
        }

        $data = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $data;
    }

    public function remaining(): int
    {
        return $this->length - $this->offset;
    }
}
