<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Script\NativeScript;
use RuntimeException;

/**
 * The deeply nested scripts the depth fixtures are made of, built two ways.
 *
 * script() puts one together out of the builders, which is how a caller would. bytes() writes the same script out as
 * a string of repeated CBOR, with no model involved at all: three bytes for an `all` around one sub-script, four for
 * an `atLeast`, and the leaf on the end. The two have to agree, and that agreement is what makes these fixtures worth
 * anything. A size arrived at by asking the encoder what it produced would prove nothing about the encoder.
 *
 * No vector in the conformance corpus nests past 64 levels, because a real script never does. The chain does: a node
 * accepted 5,383 levels on preprod. Nothing recorded anywhere is deep enough to test against, so these are generated,
 * and the arithmetic that produces every size is in tests/fixtures/deep-scripts/README.md for checking by hand.
 */
final class DeepScripts
{
    /**
     * The label the one key hash in these fixtures is the blake2b-224 of.
     *
     * Deriving it rather than writing it down means the fixtures reproduce from nothing but this file, and it holds
     * no key material because nobody knows a key whose hash this is.
     */
    public const KEY_LABEL = 'cardano-php/transaction/deep-scripts';

    /** N `all` wrappers around one `sig`. Three bytes a level, thirty-two for the leaf. */
    public const ALL_AROUND_SIG = 'all-around-sig';

    /** N-1 `all` wrappers around an `all` holding nothing, which is the cheapest leaf there is. Three bytes a level. */
    public const ALL_TO_EMPTY = 'all-to-empty';

    /** N wrappers cycling `all`, `any`, `atLeast` from the outside in, around one `sig`. */
    public const ROTATING_AROUND_SIG = 'rotating-around-sig';

    private function __construct() {}

    public static function keyHash(): string
    {
        return bin2hex(Blake2b::hash224(self::KEY_LABEL));
    }

    /**
     * The script itself, nested $depth containers deep, assembled out of the public builders.
     */
    public static function script(string $shape, int $depth): NativeScript
    {
        if ($shape === self::ALL_TO_EMPTY) {
            $script = NativeScript::all();

            for ($level = 1; $level < $depth; $level++) {
                $script = NativeScript::all($script);
            }

            return $script;
        }

        $script = NativeScript::sig(self::keyHash());

        if ($shape === self::ALL_AROUND_SIG) {
            for ($level = 0; $level < $depth; $level++) {
                $script = NativeScript::all($script);
            }

            return $script;
        }

        if ($shape !== self::ROTATING_AROUND_SIG) {
            throw new RuntimeException('No such shape: '.$shape);
        }

        for ($level = $depth - 1; $level >= 0; $level--) {
            $script = match (self::rotatingKind($level)) {
                NativeScript::ALL => NativeScript::all($script),
                NativeScript::ANY => NativeScript::any($script),
                default => NativeScript::atLeast(1, $script),
            };
        }

        return $script;
    }

    /**
     * The same script as bytes, written out by repetition rather than by the encoder under test.
     *
     * `82 01 81` is an `all` of one thing: a two-item array, constructor 1, and a one-item list. `82 02 81` is the
     * same for `any`. `83 03 01 81` is an `atLeast`, which carries its threshold and so costs a fourth byte.
     * `82 00 58 1c` opens the twenty-eight byte key hash of a `sig`, and `82 01 80` is an `all` of nothing.
     */
    public static function bytes(string $shape, int $depth): string
    {
        if ($shape === self::ALL_TO_EMPTY) {
            return str_repeat("\x82\x01\x81", $depth - 1)."\x82\x01\x80";
        }

        $leaf = "\x82\x00\x58\x1c".(string) hex2bin(self::keyHash());

        if ($shape === self::ALL_AROUND_SIG) {
            return str_repeat("\x82\x01\x81", $depth).$leaf;
        }

        if ($shape !== self::ROTATING_AROUND_SIG) {
            throw new RuntimeException('No such shape: '.$shape);
        }

        $bytes = '';

        for ($level = 0; $level < $depth; $level++) {
            $bytes .= match (self::rotatingKind($level)) {
                NativeScript::ALL => "\x82\x01\x81",
                NativeScript::ANY => "\x82\x02\x81",
                default => "\x83\x03\x01\x81",
            };
        }

        return $bytes.$leaf;
    }

    /**
     * Which container sits at a level of the rotating shape, counting from the outside in.
     */
    public static function rotatingKind(int $levelFromOutside): string
    {
        return match ($levelFromOutside % 3) {
            0 => NativeScript::ALL,
            1 => NativeScript::ANY,
            default => NativeScript::AT_LEAST,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return JsonFixture::read('deep-scripts/depths.json');
    }

    /**
     * The recorded cases, by identifier.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (self::manifest()['cases'] as $case) {
            $cases[$case['id']] = $case;
        }

        return $cases;
    }
}
