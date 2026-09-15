<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use CBOR\Tag;

/**
 * The bytes that follow a tag head.
 *
 * A tag number is an argument like any other, so it has the same five widths, and the head alone does not say which
 * one was used. Rebuilding a tag therefore needs the number and the additional information it arrived with.
 *
 * @internal This is the package's own reading machinery. It exists so a tag can be written back in the width it arrived
 * in, it is named by no public signature, and it is not part of what the package promises to keep working.
 */
final class TagPayload
{
    private function __construct() {}

    public static function numberOf(Tag $tag): int
    {
        $additionalInformation = $tag->getAdditionalInformation();
        if ($additionalInformation <= 23) {
            return $additionalInformation;
        }

        $data = $tag->getData();
        if ($data === null) {
            throw new DecodeException('A tag head wider than 23 carries no payload.');
        }

        $number = 0;
        foreach (str_split($data) as $byte) {
            $number = ($number << 8) | ord($byte);
        }

        if ($number < 0) {
            throw new DecodeException('Tag number out of range.');
        }

        return $number;
    }

    public static function forTagNumber(int $tagNumber, int $additionalInformation): ?string
    {
        if ($additionalInformation <= 23) {
            if ($additionalInformation !== $tagNumber) {
                throw new DecodeException(sprintf(
                    'Tag head %d cannot carry the tag number %d.',
                    $additionalInformation,
                    $tagNumber
                ));
            }

            return null;
        }

        return match ($additionalInformation) {
            24 => chr($tagNumber),
            25 => pack('n', $tagNumber),
            26 => pack('N', $tagNumber),
            27 => pack('J', $tagNumber),
            default => throw new DecodeException(sprintf(
                'Unsupported tag additional information %d.',
                $additionalInformation
            )),
        };
    }
}
