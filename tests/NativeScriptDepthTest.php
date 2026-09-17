<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Exception\DecodeException;
use Cardano\Transaction\Exception\ScriptException;
use Cardano\Transaction\Script\Framing;
use Cardano\Transaction\Script\NativeScript;
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
     * The wall this package used to stand in front of, and where it stands now.
     *
     * A script node is a two-item array holding a list, so it spends two levels of CBOR nesting. A decoder that
     * recurses once per level and stops at a thousand therefore reads 499 script levels and no more, which is a
     * tenth of what the chain carries. The decoder here recurses not at all: it keeps the containers it has opened
     * in an array and walks the bytes, so how deep it reads is a number that was chosen rather than whatever the
     * call stack happened to allow.
     */
    public function test_the_cbor_layer_reads_past_the_five_hundred_levels_a_recursive_decoder_reaches(): void
    {
        foreach ([499, 500, 1000, 5383, NativeScript::MAX_DEPTH] as $depth) {
            $bytes = DeepScripts::bytes(DeepScripts::ALL_TO_EMPTY, $depth);
            $value = CborCodec::decode($bytes);

            $this->assertSame(
                bin2hex($bytes),
                bin2hex(CborCodec::encode($value)),
                $depth.' levels did not come back out of the CBOR layer as they went in.'
            );
        }
    }

    /**
     * The limit the CBOR layer reads to covers every script a transaction could carry, with room over.
     *
     * One level of CBOR nesting costs at least one byte, `81`, so a document of N bytes cannot nest deeper than N
     * levels and a transaction cannot nest deeper than maxTxSize. A script at MAX_DEPTH inside a witness set spends
     * two levels a script level, plus the transaction array, the witness set map, the script list and the tag a set
     * may carry, and that sum is what has to fit.
     */
    public function test_the_cbor_limit_covers_the_deepest_script_a_transaction_could_carry(): void
    {
        $this->assertSame(
            NativeScript::MAX_TRANSACTION_BYTES,
            CborCodec::MAX_DEPTH,
            'The CBOR limit is meant to be maxTxSize, because a level of nesting costs at least one byte.'
        );

        // The transaction array, the witness set map, the script list, a set tag, two levels per script level, and
        // the array the innermost leaf is written as.
        $deepestTransaction = 4 + 2 * NativeScript::MAX_DEPTH + 1;

        $this->assertLessThan(
            CborCodec::MAX_DEPTH,
            $deepestTransaction,
            'A transaction carrying the deepest script this package reads would not fit the CBOR limit.'
        );
    }

    /**
     * A transaction carrying a deep script is read whole, and the script comes back at the depth it went in at.
     *
     * This is the assertion the wall used to stand in the way of. A script in a witness set is decoded along with
     * everything around it, so the transaction decoder, not the script reader, is what decides how deep a script
     * can travel. Both now stop in the same place.
     *
     * @param  int  $depth  how deep the script in the witness set nests
     */
    #[DataProvider('scriptDepthsInsideATransaction')]
    public function test_a_transaction_carries_a_script_as_deep_as_the_script_reader_goes(int $depth): void
    {
        $script = DeepScripts::bytes(DeepScripts::ALL_AROUND_SIG, $depth);
        $bytes = self::transactionCarrying($script);

        $transaction = TransactionDecoder::decode($bytes);

        $this->assertSame(
            bin2hex($bytes),
            bin2hex($transaction->encode()),
            'A transaction carrying a '.$depth.' level script does not survive a round trip.'
        );

        $carried = $transaction->witnessSet->nativeScripts();

        $this->assertCount(1, $carried);
        $this->assertSame(
            bin2hex($script),
            bin2hex(CborCodec::encode($carried[0])),
            'The script in the witness set is not the script that was put there.'
        );

        $read = NativeScript::fromCbor(CborCodec::encode($carried[0]));

        $this->assertSame($depth, $read->depth(), 'The script came back at a different depth than it went in at.');
        $this->assertSame(
            DeepScripts::script(DeepScripts::ALL_AROUND_SIG, $depth)->hash(),
            $transaction->witnessSet->nativeScriptHashes()[0],
            'A transaction hashes the script in its witness set exactly as that script arrived.'
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function scriptDepthsInsideATransaction(): array
    {
        return [
            'the deepest a recursive decoder reached' => [499],
            'one level past it' => [500],
            'the deepest a node has accepted' => [5383],
            'the deepest that fits around a sig' => [5450],
            'the deepest this package reads' => [NativeScript::MAX_DEPTH],
        ];
    }

    /**
     * Past the CBOR limit the answer is a refusal that names the limit, at any depth and however it is reached.
     *
     * A refusal that arrives as a dead process is not a refusal. Each case here catches what was thrown and asserts
     * what it was, and the input of each is far past the limit rather than one level over it, because a guard that
     * only fires at the boundary is a guard that read the whole document before deciding.
     */
    public function test_nesting_past_the_cbor_limit_is_refused_by_name(): void
    {
        $atTheLimit = str_repeat("\x81", CborCodec::MAX_DEPTH)."\x00";

        // The depth ceiling is reached well inside the bound on how long an input may be, because one level of
        // nesting costs one byte. A caller reading the input bound as cover for the depth ceiling is reading it
        // wrong, and this is the document that says so.
        $this->assertLessThan(CborCodec::MAX_INPUT_BYTES, strlen($atTheLimit));

        $this->assertSame(
            bin2hex($atTheLimit),
            bin2hex(CborCodec::encode(CborCodec::decode($atTheLimit))),
            'The deepest nesting the limit allows is not read and written back whole.'
        );

        foreach ([1, 2, 1000, CborCodec::MAX_DEPTH] as $over) {
            $bytes = str_repeat("\x81", CborCodec::MAX_DEPTH + $over)."\x00";
            $thrown = null;

            try {
                CborCodec::decode($bytes);
            } catch (Throwable $e) {
                $thrown = $e;
            }

            $this->assertInstanceOf(
                DecodeException::class,
                $thrown,
                CborCodec::MAX_DEPTH + $over.' levels raised nothing at all.'
            );
            $this->assertStringContainsString((string) CborCodec::MAX_DEPTH, $thrown->getMessage());
            $this->assertStringContainsString('deepest this decoder reads', $thrown->getMessage());
        }
    }

    /**
     * And the same when the nesting arrives inside a transaction rather than on its own.
     *
     * The script here is past what this package reads and past what a transaction could hold, so nothing further
     * can be said about it than that it was refused by name. What matters is that the refusal is a DecodeException
     * with the limit in it rather than a truncated witness set or a process that stopped.
     */
    public function test_a_transaction_nested_past_the_cbor_limit_is_refused_by_name(): void
    {
        $bytes = self::transactionCarrying(DeepScripts::bytes(DeepScripts::ALL_TO_EMPTY, CborCodec::MAX_DEPTH));
        $thrown = null;

        try {
            TransactionDecoder::decode($bytes);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(DecodeException::class, $thrown, 'A transaction past the limit raised nothing.');
        $this->assertStringContainsString((string) CborCodec::MAX_DEPTH, $thrown->getMessage());
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
        $this->assertInstanceOf(CborValue::class, $script->toCbor());

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
     * Which limit names the refusal moves with the size of the input. A script deep enough to be past MAX_DEPTH and
     * short enough to be worth reading is refused for its depth; one longer than CborCodec::MAX_INPUT_BYTES is
     * refused for its length, before a byte of it is read. Both are refusals by name and the second is the cheaper
     * of the two, which is the whole reason the length is looked at first.
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

        $this->assertStringContainsString(
            strlen($bytes) > CborCodec::MAX_INPUT_BYTES
                ? (string) CborCodec::MAX_INPUT_BYTES
                : (string) NativeScript::MAX_DEPTH,
            $thrown->getMessage()
        );
    }

    /**
     * A script longer than the decoder reads is refused for its length, having allocated nothing for it.
     *
     * NativeScript::fromCbor takes raw bytes and walks them itself rather than going through CborCodec, which is
     * what lets it read a script without building a CBOR tree twice as deep as the script. The bound CborCodec puts
     * on the length of an input is there for breadth rather than depth: a document of N bytes holds at most N items,
     * and past some N the answer is the allocator giving up, which is a fatal error rather than something a caller
     * can catch. Taking the bytes straight rather than through CborCodec must not mean taking them past that.
     */
    public function test_a_script_longer_than_the_decoder_reads_is_refused_by_length(): void
    {
        $bytes = str_repeat("\x82\x01\x81", CborCodec::MAX_INPUT_BYTES)."\x82\x00\x58\x1c"
            .(string) hex2bin(DeepScripts::keyHash());

        $this->assertGreaterThan(CborCodec::MAX_INPUT_BYTES, strlen($bytes));

        $thrown = null;

        try {
            NativeScript::fromCbor($bytes);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ScriptException::class, $thrown, 'An oversized script raised nothing at all.');
        $this->assertStringContainsString((string) strlen($bytes), $thrown->getMessage());
        $this->assertStringContainsString((string) CborCodec::MAX_INPUT_BYTES, $thrown->getMessage());
    }

    /**
     * And a script right at the bound is read rather than refused, so the bound is not shadowing anything real.
     *
     * The deepest script this package promises to read is a little larger than what fits a transaction, which is why
     * the bound is four times maxTxSize rather than exactly it. A case at the limit that came back refused would
     * mean the bound had been set below what the package says it reads.
     */
    public function test_the_deepest_script_this_package_reads_is_well_inside_the_input_bound(): void
    {
        $deepest = 0;

        foreach (DeepScripts::cases() as $case) {
            if ($case['refused']) {
                continue;
            }

            $deepest = max($deepest, $case['script_bytes']);
        }

        $this->assertGreaterThan(0, $deepest);
        $this->assertLessThan(CborCodec::MAX_INPUT_BYTES, $deepest);
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
