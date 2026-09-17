<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

/**
 * The encoder: one CborValue in, the bytes it stands for out, on a stack of this writer's own.
 *
 * Writing a tree is the same problem as reading one and has the same answer. An encoder that asks each child to
 * write itself descends the tree on the call stack, so a value the decoder was careful enough to read would be a
 * dead process on the way back out. Here what is left to write is a stack of items and literal bytes: a container
 * writes its own head, leaves its children behind it in order, and queues the break byte underneath them when it
 * was written without a length.
 *
 * Nothing is recomputed. Every value carries the head it was built or decoded with, so what comes out is what went
 * in, byte for byte, including the widths and framings a canonicalising encoder would quietly rewrite.
 *
 * @internal This is the package's own writing machinery. It is reached through CborCodec, it is named by no public
 * signature, and it is not part of what the package promises to keep working.
 */
final class CborWriter
{
    private function __construct() {}

    public static function write(CborValue $value): string
    {
        $out = '';

        /** @var list<CborValue|string> $stack */
        $stack = [$value];

        while ($stack !== []) {
            $item = array_pop($stack);

            if (is_string($item)) {
                $out .= $item;

                continue;
            }

            $out .= $item->head();

            switch ($item->major) {
                case CborHead::MAJOR_BYTE_STRING:
                case CborHead::MAJOR_TEXT_STRING:
                    if (! $item->isIndefinite()) {
                        $out .= $item->definitePayload();

                        break;
                    }

                    $stack[] = CborHead::BREAK;
                    self::pushItems($stack, $item->items());

                    break;

                case CborHead::MAJOR_ARRAY:
                    if ($item->isIndefinite()) {
                        $stack[] = CborHead::BREAK;
                    }

                    self::pushItems($stack, $item->items());

                    break;

                case CborHead::MAJOR_MAP:
                    if ($item->isIndefinite()) {
                        $stack[] = CborHead::BREAK;
                    }

                    $entries = $item->entries();

                    for ($index = count($entries) - 1; $index >= 0; $index--) {
                        $stack[] = $entries[$index][1];
                        $stack[] = $entries[$index][0];
                    }

                    break;

                case CborHead::MAJOR_TAG:
                    $stack[] = $item->taggedValue();

                    break;
            }
        }

        return $out;
    }

    /**
     * The items of a container, queued so that the first of them is written first.
     *
     * @param  list<CborValue|string>  $stack
     * @param  list<CborValue>  $items
     */
    private static function pushItems(array &$stack, array $items): void
    {
        for ($index = count($items) - 1; $index >= 0; $index--) {
            $stack[] = $items[$index];
        }
    }
}
