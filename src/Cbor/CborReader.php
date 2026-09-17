<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Cbor;

use Cardano\Transaction\Exception\DecodeException;
use Closure;

/**
 * The decoder: bytes in, one CborValue out, on a stack of this reader's own.
 *
 * A decoder written the obvious way calls itself once per level of nesting, which makes the deepest structure it can
 * read a property of the call stack rather than of the data. PHP cannot unwind past that, and there is nothing to
 * catch when it happens. A native script nests without a bound and the chain carries them thousands of levels deep,
 * so a transaction carrying one in its witness set is exactly the shape that wall stands in front of.
 *
 * So there is no recursion here. Each container that has been opened but not finished is one entry in $stack, which
 * holds the head it arrived in and what has been read into it so far, and the loop alternates between opening the
 * next item and handing a finished one to the container above it. How deep this reads is then a number that can be
 * chosen and a refusal that can be caught, rather than a process that dies.
 *
 * @internal This is the package's own reading machinery. It is reached through CborCodec, it is named by no public
 * signature, and it is not part of what the package promises to keep working.
 */
final class CborReader
{
    /**
     * How many levels of the path to an item a refusal names before it starts counting them instead.
     *
     * Naming every one is what the path is for at the depths a transaction usually reaches. At the depths a native
     * script reaches, the whole path is thousands of entries long, and a refusal nobody can read is not much better
     * than no refusal at all. The outermost levels are the ones that say where in a transaction the trouble is.
     */
    private const CONTEXT_LEVELS = 8;

    private function __construct() {}

    /**
     * The one item that starts at $offset, with $offset advanced past it.
     *
     * $maxDepth is how many containers may be open at once. Past it the input is refused by name rather than read,
     * and the refusal arrives having built nothing, which is what keeps a hostile input cheap to say no to.
     */
    public static function read(string $bytes, int &$offset, int $maxDepth): CborValue
    {
        /**
         * @var list<array{
         *   major: int, additionalInformation: int, argument: ?string, count: ?int, start: int,
         *   items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue,
         *   identities: array<string, true>
         * }> $stack
         */
        $stack = [];

        $context = static function () use (&$stack): string {
            $text = 'CBOR';
            $shown = 0;

            foreach ($stack as $frame) {
                if ($shown === self::CONTEXT_LEVELS) {
                    return $text.sprintf(' and %d level(s) further in', count($stack) - $shown);
                }

                $text .= match ($frame['major']) {
                    CborHead::MAJOR_ARRAY => ' item '.count($frame['items']),
                    CborHead::MAJOR_MAP => ($frame['key'] === null ? ' key ' : ' value ').count($frame['items']),
                    CborHead::MAJOR_TAG => ' tagged item',
                    default => ' chunk '.count($frame['items']),
                };
                $shown++;
            }

            return $text;
        };

        $expand = true;
        $completed = null;

        // Where in the input the item now in $completed started. A map key is named by the bytes it was written in,
        // and those bytes are in hand here; working them out again from the value would mean re-encoding it.
        $completedStart = 0;

        while (true) {
            if ($expand) {
                $expand = false;
                $start = $offset;
                $head = CborHead::read($bytes, $offset, $context);

                if ($head->isBreak()) {
                    $completed = self::closeOnBreak($stack, $completedStart, $context);

                    continue;
                }

                $opened = self::open($head, $bytes, $offset, $start, $context);

                if ($opened instanceof CborValue) {
                    $completed = $opened;
                    $completedStart = $start;

                    continue;
                }

                if (count($stack) >= $maxDepth) {
                    throw self::tooDeep($maxDepth, $context);
                }

                if (self::isFinished($opened)) {
                    $completed = self::close($opened);
                    $completedStart = $start;

                    continue;
                }

                $stack[] = $opened;
                $expand = true;

                continue;
            }

            if ($stack === []) {
                return $completed;
            }

            $top = count($stack) - 1;
            self::attach($stack[$top], $completed, $bytes, $completedStart, $offset, $context);

            if (self::isFinished($stack[$top])) {
                $frame = array_pop($stack);
                $completedStart = $frame['start'];
                $completed = self::close($frame);

                continue;
            }

            $expand = true;
        }
    }

    /**
     * The next item: a finished one when it holds nothing, or the frame an open container is assembled in.
     *
     * @param  Closure(): string  $context
     * @return CborValue|array{
     *   major: int, additionalInformation: int, argument: ?string, count: ?int, start: int,
     *   items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue,
     *   identities: array<string, true>
     * }
     */
    private static function open(
        CborHead $head,
        string $bytes,
        int &$offset,
        int $start,
        Closure $context
    ): CborValue|array {
        $indefinite = $head->isIndefinite();

        if ($head->major === CborHead::MAJOR_UNSIGNED_INTEGER || $head->major === CborHead::MAJOR_NEGATIVE_INTEGER) {
            if ($indefinite) {
                throw new DecodeException(sprintf('%s: an integer is never indefinite in length.', $context()));
            }

            return CborValue::integerAs(
                $head->major === CborHead::MAJOR_NEGATIVE_INTEGER,
                $head->additionalInformation,
                $head->argument,
            );
        }

        if ($head->major === CborHead::MAJOR_SIMPLE) {
            return CborValue::simple($head->additionalInformation, $head->argument);
        }

        if ($head->major === CborHead::MAJOR_BYTE_STRING || $head->major === CborHead::MAJOR_TEXT_STRING) {
            if (! $indefinite) {
                return CborValue::stringAs(
                    $head->major,
                    $head->additionalInformation,
                    $head->argument,
                    self::readPayload($bytes, $offset, $head->length($context), $context),
                );
            }

            return self::frame($head, null, $start);
        }

        if ($head->major === CborHead::MAJOR_TAG) {
            if ($indefinite) {
                throw new DecodeException(sprintf('%s: a tag is never indefinite in length.', $context()));
            }

            return self::frame($head, 1, $start);
        }

        // An array or a map, which is the only place the count in the head means a number of items to read.
        return self::frame($head, $indefinite ? null : $head->length($context), $start);
    }

    /**
     * A map's entries go in 'items' as key and value pairs, because no frame is ever both a map and something else
     * and a ninth slot is not free. PHP sizes an array's hashtable in powers of two, so the ninth key doubles the
     * table from eight slots to sixteen, and a frame is allocated once per level of nesting: at MAX_DEPTH that ninth
     * key costs several megabytes for nothing.
     *
     * @return array{
     *   major: int, additionalInformation: int, argument: ?string, count: ?int, start: int,
     *   items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue,
     *   identities: array<string, true>
     * }
     */
    private static function frame(CborHead $head, ?int $count, int $start): array
    {
        return [
            'major' => $head->major,
            'additionalInformation' => $head->additionalInformation,
            'argument' => $head->argument,
            'count' => $count,
            'start' => $start,
            'items' => [],
            'key' => null,
            'identities' => [],
        ];
    }

    /**
     * @param  Closure(): string  $context
     */
    private static function readPayload(string $bytes, int &$offset, int $length, Closure $context): string
    {
        if (strlen($bytes) - $offset < $length) {
            throw new DecodeException(sprintf(
                '%s: truncated input: %d byte(s) wanted at offset %d, %d available.',
                $context(),
                $length,
                $offset,
                max(strlen($bytes) - $offset, 0)
            ));
        }

        $payload = substr($bytes, $offset, $length);
        $offset += $length;

        return $payload;
    }

    /**
     * Whether a container has everything it was told to expect.
     *
     * An item written with no count in its head is never finished this way: it runs until a break byte, and that is
     * the only thing that closes it.
     *
     * @param  array{major: int, count: ?int, items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue}  $frame
     */
    private static function isFinished(array $frame): bool
    {
        if ($frame['count'] === null) {
            return false;
        }

        if ($frame['major'] === CborHead::MAJOR_MAP) {
            return $frame['key'] === null && count($frame['items']) === $frame['count'];
        }

        return count($frame['items']) === $frame['count'];
    }

    /**
     * One finished item handed to the container above it.
     *
     * @param  array{
     *   major: int, additionalInformation: int, argument: ?string, count: ?int, start: int,
     *   items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue,
     *   identities: array<string, true>
     * }  $frame
     * @param  Closure(): string  $context
     */
    private static function attach(
        array &$frame,
        CborValue $value,
        string $bytes,
        int $start,
        int $end,
        Closure $context
    ): void {
        if ($frame['major'] === CborHead::MAJOR_MAP) {
            if ($frame['key'] === null) {
                $identity = self::keyIdentity($value, $bytes, $start, $end);

                if (isset($frame['identities'][$identity])) {
                    throw new DecodeException(sprintf(
                        '%s: the same key is written twice in one map.',
                        $context()
                    ));
                }

                $frame['identities'][$identity] = true;
                $frame['key'] = $value;

                return;
            }

            $frame['items'][] = [$frame['key'], $value];
            $frame['key'] = null;

            return;
        }

        if ($frame['major'] === CborHead::MAJOR_BYTE_STRING || $frame['major'] === CborHead::MAJOR_TEXT_STRING) {
            // RFC 8949 section 3.2.3: the chunks of an indefinite length string are definite length strings of the
            // same major type, and nothing else. A chunk that is anything else is not a longer string, it is a
            // different document.
            if ($value->major !== $frame['major'] || $value->isIndefinite()) {
                throw new DecodeException(sprintf(
                    '%s: an indefinite length string holds definite length strings of its own type, got %s.',
                    $context(),
                    $value->describe()
                ));
            }
        }

        $frame['items'][] = $value;
    }

    /**
     * The break byte, which closes the innermost container written without a count.
     *
     * @param  list<array{
     *   major: int, additionalInformation: int, argument: ?string, count: ?int, start: int,
     *   items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue,
     *   identities: array<string, true>
     * }>  $stack
     * @param  Closure(): string  $context
     */
    private static function closeOnBreak(array &$stack, int &$start, Closure $context): CborValue
    {
        if ($stack === []) {
            throw new DecodeException(sprintf(
                '%s: a break byte with no indefinite length item open.',
                $context()
            ));
        }

        $top = count($stack) - 1;

        if ($stack[$top]['count'] !== null) {
            throw new DecodeException(sprintf(
                '%s: a break byte inside an item that stated its own length.',
                $context()
            ));
        }

        if ($stack[$top]['key'] !== null) {
            throw new DecodeException(sprintf('%s: a map key with no value after it.', $context()));
        }

        $frame = array_pop($stack);
        $start = $frame['start'];

        return self::close($frame);
    }

    /**
     * The value a finished frame stands for, written at the head it arrived in.
     *
     * @param  array{
     *   major: int, additionalInformation: int, argument: ?string, count: ?int, start: int,
     *   items: list<CborValue>|list<array{CborValue, CborValue}>, key: ?CborValue,
     *   identities: array<string, true>
     * }  $frame
     */
    private static function close(array $frame): CborValue
    {
        return match ($frame['major']) {
            CborHead::MAJOR_ARRAY => CborValue::sequenceAs(
                $frame['additionalInformation'],
                $frame['argument'],
                $frame['items'],
            ),
            CborHead::MAJOR_MAP => CborValue::mapAs(
                $frame['additionalInformation'],
                $frame['argument'],
                $frame['items'],
            ),
            CborHead::MAJOR_TAG => CborValue::taggedAs(
                $frame['additionalInformation'],
                $frame['argument'],
                $frame['items'][0],
            ),
            default => CborValue::chunkedString($frame['major'], $frame['items']),
        };
    }

    /**
     * What makes two map keys the same key.
     *
     * RFC 8949 section 5.6 asks a decoder to refuse a map that writes one key twice, and the question is what "the
     * same key" means when CBOR can write one value several ways. The answer here is the data model rather than the
     * bytes for everything that has one: the integer 1 is the same key however wide the head it arrived in, and a
     * string is its content whichever framing carried it. A key that is itself an array, a map or a tag is compared
     * by the bytes it was written in, because two containers written differently are two different keys as far as
     * anything hashing them is concerned, and a transaction has never carried one.
     *
     * Those bytes are the slice of the input the key was read from, not a re-encoding of it. The two are the same
     * string, because a value read here writes back exactly what it was read from, and taking the slice costs one
     * memory copy where re-encoding costs a walk of the whole key and a fresh string built a node at a time. That is
     * what took a transaction-sized document from twenty-one seconds to eighty-three milliseconds.
     *
     * What it did not do is make the cost linear in the input, and nothing here can. Naming a key costs its own
     * length whichever way the name is taken, and a key nested inside another key has its bytes counted again by
     * every key above it. So the work is the sum of the lengths of every map key in the document, which for keys
     * nested one inside the next runs as the square of how deep they go: a chain of container keys 16,383 deep is
     * 32,767 bytes of input and 268 MB of copying. Written as a bound, it is the smaller of CborCodec::MAX_DEPTH and
     * the input length, times the input length.
     *
     * Both of those bounds are therefore load bearing, and that is worth knowing before either is moved. At the
     * present values the worst shape costs about half a second, most of which is reading the document at all. Raising
     * MAX_INPUT_BYTES raises this as its square until MAX_DEPTH catches it, and raising MAX_DEPTH does the same until
     * MAX_INPUT_BYTES catches it. Neither is a change that only costs memory.
     */
    private static function keyIdentity(CborValue $key, string $bytes, int $start, int $end): string
    {
        return match (true) {
            $key->isInteger() => 'i:'.$key->integerText(),
            $key->isByteString(), $key->isIndefiniteByteString() => 'b:'.$key->stringValue(),
            $key->isTextString() => 't:'.$key->stringValue(),
            $key->isSimple() => 's:'.bin2hex($key->head()),
            default => 'x:'.substr($bytes, $start, $end - $start),
        };
    }

    /**
     * @param  Closure(): string  $context
     */
    private static function tooDeep(int $maxDepth, Closure $context): DecodeException
    {
        return new DecodeException(sprintf(
            '%s: nesting runs past %d levels, which is the deepest this decoder reads. One level of nesting costs '
            .'at least one byte, so nothing inside the %d bytes a transaction holds can nest that far.',
            $context(),
            $maxDepth,
            $maxDepth
        ));
    }
}
