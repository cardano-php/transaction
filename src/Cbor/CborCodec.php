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
 */
final class CborCodec
{
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
