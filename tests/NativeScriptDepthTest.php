<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Exception\ScriptException;
use Cardano\Transaction\Script\Framing;
use Cardano\Transaction\Script\NativeScript;
use CBOR\ListObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * How deep a script this reads, and whether that covers what the chain carries.
 *
 * The grammar bounds nothing. `script_all = (1, [* native_script])` recurses without a limit, the ledger's evaluator
 * counts no depth, and the Conway CDDL does not use the words depth, recursion or nesting anywhere. What bounds a
 * script is `maxTxSize`, 16,384 bytes, because a script reaches the chain only inside a transaction and every route
 * it takes is weighed against that. A level of nesting costs three bytes, so a script tops out at 5,461 levels, and
 * the deepest a node has actually accepted is 5,383.
 *
 * Two things have to hold at once, and the second is the one that is easy to get wrong. Every script the ledger
 * accepts reads. Every depth past that is refused with an exception, at any depth, rather than read part way or
 * taken out on the process.
 */
class NativeScriptDepthTest extends TestCase
{
    /**
     * The cases a transaction could carry, which are the ones the package promises to read.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function reachableCases(): array
    {
        return array_map(
            static fn (array $case): array => [$case],
            array_filter(DeepScripts::cases(), static fn (array $case): bool => ! $case['refused'])
        );
    }

    // ----------------------------------------------------------- where the limit comes from

    /**
     * The limit is the deepest a transaction could carry, worked out rather than chosen.
     *
     * A script of N bytes cannot nest deeper than N divided by what a level costs, and a script on chain is at most
     * a transaction long. Seventy-eight levels of that sit above anything a node has accepted, and they are the only
     * margin there is: the limit itself is already a script that fills a transaction and leaves nothing for it.
     */
    public function test_the_limit_is_the_deepest_a_transaction_could_carry(): void
    {
        $this->assertSame(16384, NativeScript::MAX_TRANSACTION_BYTES, 'maxTxSize on mainnet, preprod and preview.');
        $this->assertSame(3, NativeScript::MIN_BYTES_PER_LEVEL);

        $this->assertSame(
            intdiv(NativeScript::MAX_TRANSACTION_BYTES, NativeScript::MIN_BYTES_PER_LEVEL),
            NativeScript::MAX_DEPTH,
            'The limit is not what the arithmetic says it is.'
        );

        $this->assertSame(5461, NativeScript::MAX_DEPTH);
        $this->assertSame(78, NativeScript::MAX_DEPTH - 5383, 'The margin over the deepest a node has accepted.');

        $atTheLimit = DeepScripts::bytes(DeepScripts::ALL_TO_EMPTY, NativeScript::MAX_DEPTH);

        $this->assertSame(16383, strlen($atTheLimit), 'One byte short of a whole transaction, with nothing in it.');
        $this->assertGreaterThan(
            NativeScript::MAX_TRANSACTION_BYTES,
            strlen(DeepScripts::bytes(DeepScripts::ALL_TO_EMPTY, NativeScript::MAX_DEPTH + 1)),
            'And one level further does not fit a transaction at all.'
        );
    }

    /**
     * The three bytes a level costs, measured rather than asserted.
     *
     * `82 01 81` is an `all` around one sub-script: a two-item array, the constructor, and a list of one. Nothing
     * encodes a level in fewer, which is what makes 5,461 the ceiling rather than something larger.
     */
    #[DataProvider('levelCounts')]
    public function test_a_level_of_nesting_costs_three_bytes(int $levels): void
    {
        $leaf = strlen(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 0));
        $nested = strlen(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, $levels));

        $this->assertSame($levels * NativeScript::MIN_BYTES_PER_LEVEL, $nested - $leaf);
        $this->assertSame($nested, strlen(DeepScripts::script(DeepScripts::ALL_AROUND_SIG, $levels)->cbor()));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function levelCounts(): array
    {
        return ['one' => [1], 'two' => [2], 'twenty three' => [23], 'twenty four' => [24], 'a hundred' => [100]];
    }

    /**
     * The decoder this package used to lean on gives out at 499 levels, which is why it no longer reads scripts.
     *
     * A script node is a two-item array holding a list, so it spends two of the CBOR library's thousand levels. The
     * library's own comment says why the limit is there: it decodes by recursion, and PHP cannot unwind a recursion
     * that deep. Raising the number would turn a refusal that can be caught into a process that dies, so the bytes
     * are walked here instead.
     */
    public function test_the_cbor_library_stops_at_499_levels_and_this_package_does_not(): void
    {
        $this->assertSame(
            499,
            NativeScript::fromCbor(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 499))->depth()
        );

        CborCodec::decode(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 499));

        try {
            CborCodec::decode(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 500));
            $this->fail('The CBOR library read 500 levels, so this test no longer says anything.');
        } catch (DecodeException $e) {
            $this->assertStringContainsString('Maximum nesting depth of 1000 exceeded', $e->getMessage());
        }

        $this->assertSame(
            5383,
            NativeScript::fromCbor(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 5383))->depth(),
            'The package reads ten times past the library, because it does not hand it the tree.'
        );
    }

    /**
     * A transaction carrying a deep script stops earlier than the script reader does, and says so.
     *
     * A script in a witness set is decoded by the CBOR library along with everything around it, so reading the
     * transaction is still bounded by the library's thousand levels rather than by this package's. The refusal is a
     * DecodeException that names both the limit and the reader that has none, which is the difference between a
     * wall a caller can work around and one that kills the process.
     */
    public function test_a_transaction_carrying_a_deep_script_refuses_and_names_the_reader_that_does_not(): void
    {
        $shallow = self::transactionCarrying(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 64));
        $hashes = TransactionDecoder::decode($shallow)->witnessSet->nativeScriptHashes();

        $this->assertSame(
            DeepScripts::script(DeepScripts::ALL_AROUND_SIG, 64)->hash(),
            $hashes[0],
            'A transaction hashes the script in its witness set exactly as that script arrived.'
        );

        $deep = self::transactionCarrying(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 5383));

        try {
            TransactionDecoder::decode($deep);
            $this->fail('A transaction carrying a 5,383 level script was read, so this test says nothing.');
        } catch (DecodeException $e) {
            $this->assertStringContainsString('Maximum nesting depth', $e->getMessage());
            $this->assertStringContainsString('NativeScript::fromCbor', $e->getMessage());
        }

        $this->assertSame(
            5383,
            NativeScript::fromCbor(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 5383))->depth(),
            'And the script those bytes hold is read on its own without trouble.'
        );
    }

    /**
     * The smallest transaction that carries one native script: one input, no outputs, the balance as the fee.
     */
    private static function transactionCarrying(string $script): string
    {
        $body = "\xa3"
            ."\x00\x81\x82\x58\x20".str_repeat("\x11", 32)."\x00"
            ."\x01\x80"
            ."\x02\x1a\x00\x0f\x42\x40";

        return "\x84".$body."\xa1\x01\x81".$script."\xf5\xf6";
    }

    // ------------------------------------------------------------------ the recorded cases

    /**
     * Each recorded script weighs what the fixture says, and hashes to what it says.
     *
     * The builders and the raw byte form are two independent ways to the same script, so this checks the encoder
     * against arithmetic rather than against itself.
     *
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('reachableCases')]
    public function test_a_recorded_script_is_the_size_and_the_hash_it_was_recorded_at(array $case): void
    {
        $bytes = DeepScripts::bytes($case['shape'], $case['depth']);
        $script = DeepScripts::script($case['shape'], $case['depth']);

        $this->assertSame($case['script_bytes'], strlen($bytes), $case['id'].' is not the size it was recorded at.');
        $this->assertSame($bytes, $script->cbor(), $case['id'].' does not encode to the bytes the rule writes.');
        $this->assertSame($case['depth'], $script->depth(), $case['id'].' does not nest as deep as it was recorded.');
        $this->assertSame($case['script_hash'], $script->hashHex(), $case['id'].' hashes to something else now.');
    }

    /**
     * And each one reads back out of its bytes as the script that wrote them.
     *
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('reachableCases')]
    public function test_a_recorded_script_reads_back_from_its_bytes(array $case): void
    {
        $read = NativeScript::fromCbor(DeepScripts::bytes($case['shape'], $case['depth']));

        $this->assertSame($case['depth'], $read->depth());
        $this->assertSame($case['script_hash'], $read->hashHex());
    }

    /**
     * The fixture records the limit the package was built to, so a change to one shows up against the other.
     */
    public function test_the_fixture_records_the_limit_it_was_generated_against(): void
    {
        $manifest = DeepScripts::manifest();

        $this->assertSame(NativeScript::MAX_DEPTH, $manifest['max_depth']);
        $this->assertSame(NativeScript::MAX_TRANSACTION_BYTES, $manifest['max_transaction_bytes']);
        $this->assertSame(NativeScript::MIN_BYTES_PER_LEVEL, $manifest['min_bytes_per_level']);
        $this->assertSame(DeepScripts::keyHash(), $manifest['key_hash']);
    }

    // ------------------------------------------------------------------- every traversal

    /**
     * Every walk over a script runs at the depth a node has accepted, not only the one that reads it.
     *
     * One traversal left on the call stack would set the limit for the whole class however good the others were, so
     * this asks each of them for an answer at 5,383 levels rather than asking whether it returns at all.
     */
    public function test_every_traversal_runs_at_the_depth_a_node_accepted(): void
    {
        $depth = 5383;
        $keyHash = DeepScripts::keyHash();
        $script = DeepScripts::script(DeepScripts::ALL_AROUND_SIG, $depth);

        $this->assertSame($depth, $script->depth());
        $this->assertSame(DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, $depth), $script->cbor());
        $this->assertSame(56, strlen($script->hashHex()));
        $this->assertInstanceOf(ListObject::class, $script->toCbor());

        $this->assertSame([$keyHash], $script->keyHashes());
        $this->assertSame([$keyHash], $script->unboundedSigners());
        $this->assertSame([], $script->timeBoundSigners());
        $this->assertFalse($script->isTimeBound());

        $this->assertTrue($script->isSatisfiedBy([$keyHash]));
        $this->assertFalse($script->isSatisfiedBy([]));

        $this->assertSame([Framing::Definite, Framing::CardanoBinary], $script->framings());
        $this->assertSame($script->hashHex(), $script->framed(Framing::Definite)->hashHex());

        $array = $script->toArray();
        $this->assertSame($depth, self::depthOfArray($array));
        $this->assertSame($script->hashHex(), NativeScript::fromArray($array)->hashHex());
    }

    /**
     * The time bound walk too, which is the one that carries an answer down the tree rather than up it.
     */
    public function test_a_time_bound_deep_script_partitions_its_signers(): void
    {
        $keyHash = DeepScripts::keyHash();
        $inner = DeepScripts::script(DeepScripts::ALL_AROUND_SIG, 5382);
        $script = NativeScript::all(NativeScript::after(100), $inner);

        $this->assertSame(5383, $script->depth());
        $this->assertTrue($script->isTimeBound());
        $this->assertSame([], $script->unboundedSigners());
        $this->assertSame([$keyHash], $script->timeBoundSigners());
        $this->assertSame([$keyHash], $script->keyHashes());

        $this->assertTrue($script->isSatisfiedBy([$keyHash], 100));
        $this->assertFalse($script->isSatisfiedBy([$keyHash], 99));
    }

    /**
     * A wide container at depth reframes all the way down, which is what moves the hash and the address with it.
     */
    public function test_a_deep_script_reframes_at_every_level(): void
    {
        $script = NativeScript::all(...array_fill(0, 24, NativeScript::sig(DeepScripts::keyHash())));

        for ($level = 0; $level < 2000; $level++) {
            $script = NativeScript::all($script);
        }

        $this->assertSame(2001, $script->depth());
        $this->assertSame([Framing::CardanoBinary], $script->framings());

        $definite = $script->framed(Framing::Definite);

        $this->assertSame([Framing::Definite], $definite->framings());
        $this->assertNotSame($script->hashHex(), $definite->hashHex());
        $this->assertSame($definite->hashHex(), NativeScript::fromCbor($definite->cbor())->hashHex());
    }

    /**
     * Nothing in a deep script is quietly dropped: what comes back out is byte for byte what went in.
     */
    public function test_a_deep_script_is_read_whole_rather_than_truncated(): void
    {
        $bytes = DeepScripts::bytes(DeepScripts::ROTATING_AROUND_SIG, 5383);
        $read = NativeScript::fromCbor($bytes);

        $this->assertSame(bin2hex($bytes), $read->cborHex());
        $this->assertSame(5383, $read->depth());
        $this->assertSame([DeepScripts::keyHash()], $read->keyHashes());
    }

    /**
     * Bytes that run out part way through a deep script are a refusal, not a short script.
     */
    public function test_a_deep_script_cut_short_is_refused(): void
    {
        $bytes = DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, 5383);

        try {
            NativeScript::fromCbor(substr($bytes, 0, strlen($bytes) - 1));
            $this->fail('A truncated script was read as a script.');
        } catch (ScriptException $e) {
            $this->assertStringContainsString('Not a native script', $e->getMessage());
        }
    }

    // ----------------------------------------------------------------------- the refusals

    /**
     * The far side of the limit is an exception, and the test has to see the exception rather than the absence of a
     * result.
     *
     * A refusal that arrives as a segfault is not a refusal: there is nothing to catch, nothing to log, and no way
     * for a caller to tell a script it cannot read from a script that killed the process. So each case below catches
     * what was thrown and asserts what it was.
     */
    public function test_a_script_one_level_past_the_limit_is_refused_by_name(): void
    {
        $case = DeepScripts::cases()['past-the-limit'];

        $this->assertTrue($case['refused']);
        $this->assertSame(NativeScript::MAX_DEPTH + 1, $case['depth']);

        $bytes = DeepScripts::bytes($case['shape'], $case['depth']);

        $this->assertSame($case['script_bytes'], strlen($bytes));

        $thrown = null;

        try {
            NativeScript::fromCbor($bytes);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ScriptException::class, $thrown, 'Reading past the limit raised nothing at all.');
        $this->assertStringContainsString((string) NativeScript::MAX_DEPTH, $thrown->getMessage());
        $this->assertStringContainsString('nests', $thrown->getMessage());
    }

    /**
     * And at every depth past it, not only at the first one.
     *
     * A guard that fires one level over and not a thousand over is a guard that reads the whole input before
     * deciding. The million level case is three megabytes of input, and the point of it is that the refusal arrives
     * having built nothing, as quickly and as cheaply as the one that is a single level over.
     *
     * @param  int  $depth  how deep the script nests
     */
    #[DataProvider('depthsPastTheLimit')]
    public function test_every_depth_past_the_limit_is_the_same_clean_refusal(int $depth): void
    {
        $bytes = str_repeat("\x82\x01\x81", $depth)."\x82\x00\x58\x1c".(string) hex2bin(DeepScripts::keyHash());

        $thrown = null;

        try {
            NativeScript::fromCbor($bytes);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ScriptException::class, $thrown, $depth.' levels raised nothing at all.');
        $this->assertStringContainsString((string) NativeScript::MAX_DEPTH, $thrown->getMessage());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function depthsPastTheLimit(): array
    {
        return [
            'one level over' => [NativeScript::MAX_DEPTH + 1],
            'a thousand over' => [NativeScript::MAX_DEPTH + 1000],
            'twice over' => [NativeScript::MAX_DEPTH * 2],
            'a million levels' => [1000000],
        ];
    }

    /**
     * The builders stop in the same place and with the same message, so the limit belongs to the script rather than
     * to whichever door it came in by.
     */
    public function test_building_past_the_limit_is_the_same_refusal_as_reading_past_it(): void
    {
        $atTheLimit = DeepScripts::script(DeepScripts::ALL_TO_EMPTY, NativeScript::MAX_DEPTH);

        $this->assertSame(NativeScript::MAX_DEPTH, $atTheLimit->depth());

        $thrown = null;

        try {
            NativeScript::all($atTheLimit);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ScriptException::class, $thrown, 'A script was built past the limit without a word.');
        $this->assertStringContainsString((string) (NativeScript::MAX_DEPTH + 1), $thrown->getMessage());
        $this->assertStringContainsString((string) NativeScript::MAX_DEPTH, $thrown->getMessage());
    }

    /**
     * The JSON form carries far less, and says so rather than writing out a file that parses as nothing.
     *
     * PHP's own JSON writer answers a nesting it cannot reach with false, and json_encode's default allowance is 512
     * levels, which a script spends two of per level. Returning that false as a string would hand back an empty
     * script file, which is the silent version of this failure.
     */
    public function test_the_json_form_stops_at_its_own_limit_and_says_so(): void
    {
        $atTheLimit = DeepScripts::script(DeepScripts::ALL_AROUND_SIG, NativeScript::MAX_JSON_DEPTH);
        $json = $atTheLimit->toJson();

        $this->assertSame($atTheLimit->hashHex(), NativeScript::fromJson($json)->hashHex());

        try {
            DeepScripts::script(DeepScripts::ALL_AROUND_SIG, NativeScript::MAX_JSON_DEPTH + 1)->toJson();
            $this->fail('A script past the JSON limit was written out anyway.');
        } catch (ScriptException $e) {
            $this->assertStringContainsString((string) NativeScript::MAX_JSON_DEPTH, $e->getMessage());
        }
    }

    /**
     * And reading one back that is too deep for PHP's JSON parser is a refusal rather than a null.
     */
    public function test_json_too_deep_for_php_to_read_is_refused_by_name(): void
    {
        $depth = NativeScript::MAX_JSON_DEPTH + 1;
        $json = str_repeat('{"type":"all","scripts":[', $depth)
            .'{"type":"sig","keyHash":"'.DeepScripts::keyHash().'"}'
            .str_repeat(']}', $depth);

        try {
            NativeScript::fromJson($json);
            $this->fail('A script file past the JSON limit was read anyway.');
        } catch (ScriptException $e) {
            $this->assertStringContainsString((string) NativeScript::MAX_JSON_DEPTH, $e->getMessage());
        }

        // The same script through the array form, which is this package's own reader and goes as deep as CBOR does.
        $this->assertSame($depth, NativeScript::fromArray((array) json_decode($json, true, 4096))->depth());
    }

    /**
     * How deep a JSON-shaped array nests, worked out without recursing into it.
     *
     * @param  array<string, mixed>  $array
     */
    private static function depthOfArray(array $array): int
    {
        $depth = 0;
        $node = $array;

        while (isset($node['scripts'])) {
            $depth++;

            if ($node['scripts'] === []) {
                break;
            }

            $node = $node['scripts'][0];
        }

        return $depth;
    }
}
