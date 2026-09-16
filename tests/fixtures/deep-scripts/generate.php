<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Script\NativeScript;

/**
 * Writes depths.json, the record of what each generated deep script weighs and hashes to.
 *
 * Run it from the package root:
 *
 *   php tests/fixtures/deep-scripts/generate.php
 *
 * Every size here is arithmetic a reviewer can redo without running anything, and README.md alongside this file
 * shows the sum for each case. The hashes are this package's own output, so they pin the encoder at depths no
 * recorded script reaches rather than proving it against an outside answer. The corpus under ../arachne is what
 * proves it against an outside answer, and it stops at 64 levels.
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';

$plan = [
    [
        'id' => 'one-level',
        'shape' => DeepScripts::ALL_AROUND_SIG,
        'depth' => 1,
        'why' => 'The smallest nest there is, short enough to read off by eye and check the three byte cost against.',
    ],
    [
        'id' => 'past-the-corpus',
        'shape' => DeepScripts::ALL_AROUND_SIG,
        'depth' => 65,
        'why' => 'One level past the deepest vector in the conformance corpus, which is where generated cases start.',
    ],
    [
        'id' => 'rotating-past-the-corpus',
        'shape' => DeepScripts::ROTATING_AROUND_SIG,
        'depth' => 65,
        'why' => 'The same depth with all, any and atLeast alternating, so no container kind is only tested shallow.',
    ],
    [
        'id' => 'node-accepted',
        'shape' => DeepScripts::ALL_AROUND_SIG,
        'depth' => 5383,
        'why' => 'The deepest script a node has accepted, in preprod transaction '
            .'f90dce5765108da976abdbb9fc618f9a6ffd9fa4d93b2f288eed1808545424c9. The 16,181 bytes are the size that '
            .'transaction was measured at.',
    ],
    [
        'id' => 'rotating-at-the-node-depth',
        'shape' => DeepScripts::ROTATING_AROUND_SIG,
        'depth' => 5383,
        'why' => 'Every container kind at the depth the chain has carried. An atLeast costs a fourth byte a '
            .'level, so this one is larger than a transaction and could not be carried. What it exercises is '
            .'the reader and the encoder at depth rather than what fits.',
    ],
    [
        'id' => 'largest-sig-terminated',
        'shape' => DeepScripts::ALL_AROUND_SIG,
        'depth' => 5450,
        'why' => 'The deepest nest around a sig that still fits maxTxSize, before the transaction around it is paid '
            .'for: (16384 - 32) / 3.',
    ],
    [
        'id' => 'at-the-limit',
        'shape' => DeepScripts::ALL_TO_EMPTY,
        'depth' => NativeScript::MAX_DEPTH,
        'why' => 'The deepest script of any shape that fits inside a 16,384 byte transaction, at 16,383 bytes, and '
            .'so the deepest this package reads. Nothing can reach it: a script this long leaves no room for the '
            .'transaction carrying it.',
    ],
    [
        'id' => 'past-the-limit',
        'shape' => DeepScripts::ALL_TO_EMPTY,
        'depth' => NativeScript::MAX_DEPTH + 1,
        'why' => 'One level further, at 16,386 bytes, which is past what a transaction holds. Refused by name rather '
            .'than read part way.',
    ],
];

$cases = [];

foreach ($plan as $entry) {
    $bytes = DeepScripts::bytes($entry['shape'], $entry['depth']);
    $refused = $entry['depth'] > NativeScript::MAX_DEPTH;

    $cases[] = [
        'id' => $entry['id'],
        'why' => $entry['why'],
        'shape' => $entry['shape'],
        'depth' => $entry['depth'],
        'script_bytes' => strlen($bytes),
        'script_hash' => $refused ? null : DeepScripts::script($entry['shape'], $entry['depth'])->hashHex(),
        'refused' => $refused,
    ];
}

$manifest = [
    'what' => 'Generated native scripts at the depths that decide whether this package can read what the chain '
        .'carries. No recorded script is deep enough to serve, so these are built from a rule rather than fetched.',
    'generator' => 'tests/fixtures/deep-scripts/generate.php',
    'key_label' => DeepScripts::KEY_LABEL,
    'key_hash' => DeepScripts::keyHash(),
    'max_depth' => NativeScript::MAX_DEPTH,
    'max_transaction_bytes' => NativeScript::MAX_TRANSACTION_BYTES,
    'min_bytes_per_level' => NativeScript::MIN_BYTES_PER_LEVEL,
    'cases' => $cases,
];

$path = __DIR__.'/depths.json';
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

printf("Wrote %d cases to %s\n", count($cases), $path);
