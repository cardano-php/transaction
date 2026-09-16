<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use CBOR\CBORObject;
use CBOR\Decoder;
use Throwable;

/**
 * The CBOR layer, over spomky-labs/cbor-php.
 *
 * Two things are added to the library's decoder. The input has to be exactly one item, with nothing after it, and
 * every failure below arrives as a DecodeException rather than as whatever the library happened to throw.
 *
 * The library decodes by recursion and refuses anything nested past a thousand levels for that reason. Raising that
 * number is not an option: it guards a recursion PHP cannot unwind, and past it the process dies with nothing to
 * catch. A transaction's own structure is nowhere near the limit, but a native script nests without a bound and the
 * chain carries them thousands of levels deep, so NativeScript reads its own bytes rather than coming through here.
 * A transaction whose witness set holds a script that deep still stops at the library's limit, and the refusal below
 * says so rather than leaving the caller with the library's own wording.
 */
final class CborCodec
{
    /** What the library says when its own nesting limit is what stopped it. */
    private const NESTING_REFUSAL = 'Maximum nesting depth';

    private function __construct() {}

    public static function decode(string $bytes): CBORObject
    {
        if ($bytes === '') {
            throw new DecodeException('Empty input.');
        }

        $stream = new CborStream($bytes);

        try {
            $object = Decoder::create()->decode($stream);
        } catch (DecodeException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), self::NESTING_REFUSAL)) {
                throw new DecodeException(
                    'Malformed CBOR: '.$e->getMessage().' The CBOR library decodes by recursion and goes no '
                    .'deeper. A native script nests without a bound, and one on its own is read by '
                    .'NativeScript::fromCbor, which walks the bytes instead; a transaction carrying a script that '
                    .'deep is past what this decoder reaches.',
                    0,
                    $e
                );
            }

            throw new DecodeException('Malformed CBOR: '.$e->getMessage(), 0, $e);
        }

        if ($stream->remaining() !== 0) {
            throw new DecodeException(sprintf(
                'Malformed CBOR: %d byte(s) follow the top level item.',
                $stream->remaining()
            ));
        }

        return $object;
    }

    public static function encode(CBORObject $object): string
    {
        return (string) $object;
    }
}
