<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Exception\AddressException;
use Cardano\Transaction\Exception\ScriptException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Script\NativeScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How a native script is written, and what it asks of the transaction that spends under it.
 *
 * The encodings here are checked against the published ledger CDDL byte by byte rather than against a value this
 * code produced, and the two time constructors get more attention than the rest because they are the pair that can
 * be wrong in a way that still works.
 */
class NativeScriptTest extends TestCase
{
    /** Twenty-eight bytes each, none a palindrome or a run of one byte, so a reversed or truncated hash shows up. */
    private const ALICE = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b';

    private const BOB = '202122232425262728292a2b2c2d2e2f303132333435363738393a3b';

    private const CAROL = '404142434445464748494a4b4c4d4e4f505152535455565758595a5b';

    // ---------------------------------------------------------------- encoding

    /**
     * `before` is invalid_hereafter, tag 5, and `after` is invalid_before, tag 4. The ledger names them from the
     * transaction's side and cardano-cli from the script's, which is where the two cross over. Swapping them gives a
     * script that is valid for exactly the period it was written to exclude.
     */
    public function test_before_is_tag_five_and_after_is_tag_four(): void
    {
        $this->assertSame('82051864', NativeScript::before(100)->cborHex());
        $this->assertSame('82041864', NativeScript::after(100)->cborHex());
    }

    public function test_a_sig_clause_is_tag_zero_and_a_twenty_eight_byte_string(): void
    {
        $this->assertSame('8200581c'.self::ALICE, NativeScript::sig(self::ALICE)->cborHex());
    }

    public function test_all_is_tag_one_and_any_is_tag_two(): void
    {
        $sig = '8200581c'.self::ALICE;

        $this->assertSame('820181'.$sig, NativeScript::all(NativeScript::sig(self::ALICE))->cborHex());
        $this->assertSame('820281'.$sig, NativeScript::any(NativeScript::sig(self::ALICE))->cborHex());
    }

    public function test_at_least_is_tag_three_with_the_threshold_before_the_scripts(): void
    {
        $script = NativeScript::atLeast(2, NativeScript::sig(self::ALICE), NativeScript::sig(self::BOB));

        $this->assertSame(
            '83030282'.'8200581c'.self::ALICE.'8200581c'.self::BOB,
            $script->cborHex(),
        );
    }

    public static function slotEncodings(): array
    {
        return [
            'inline, largest' => [23, '820517'],
            'one byte, smallest' => [24, '82051818'],
            'one byte, largest' => [255, '820518ff'],
            'two bytes, smallest' => [256, '8205190100'],
            'two bytes, largest' => [65535, '820519ffff'],
            'four bytes, smallest' => [65536, '82051a00010000'],
            'four bytes, largest' => [4294967295, '82051affffffff'],
            'eight bytes, smallest' => [4294967296, '82051b0000000100000000'],
        ];
    }

    /**
     * Every head has to be the shortest that holds its value. A wider head encodes the same number and hashes to a
     * different script, which is a different address.
     */
    #[DataProvider('slotEncodings')]
    public function test_a_slot_is_written_in_the_shortest_head_that_holds_it(int $slot, string $expected): void
    {
        $this->assertSame($expected, NativeScript::before($slot)->cborHex());
    }

    public function test_the_order_of_the_sub_scripts_is_part_of_the_script(): void
    {
        $one = NativeScript::any(NativeScript::sig(self::ALICE), NativeScript::sig(self::BOB));
        $other = NativeScript::any(NativeScript::sig(self::BOB), NativeScript::sig(self::ALICE));

        $this->assertNotSame($one->cborHex(), $other->cborHex());
        $this->assertNotSame($one->hashHex(), $other->hashHex());
    }

    // ----------------------------------------------------------------- hashing

    /**
     * The tag byte is what separates a native script from PlutusV1. Without it the digest is still twenty-eight bytes
     * and still looks exactly like a script hash.
     */
    public function test_the_language_tag_byte_is_part_of_the_hash(): void
    {
        $script = NativeScript::sig(self::ALICE);

        $this->assertSame(
            bin2hex(Blake2b::hash224("\x00".$script->cbor())),
            $script->hashHex(),
        );
        $this->assertNotSame(
            bin2hex(Blake2b::hash224($script->cbor())),
            $script->hashHex(),
        );
    }

    public function test_a_script_hash_is_twenty_eight_bytes(): void
    {
        $this->assertSame(28, strlen(NativeScript::sig(self::ALICE)->hash()));
    }

    public function test_flipping_one_bit_of_a_key_hash_moves_the_hash_and_the_address(): void
    {
        $flipped = substr(self::ALICE, 0, 55).dechex(hexdec(substr(self::ALICE, 55, 1)) ^ 1);

        $original = NativeScript::sig(self::ALICE);
        $changed = NativeScript::sig($flipped);

        $this->assertNotSame($original->hashHex(), $changed->hashHex());
        $this->assertNotSame(
            $original->enterpriseAddress(Network::Mainnet)->toBech32(),
            $changed->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
    }

    public function test_one_slot_of_difference_moves_the_address(): void
    {
        $this->assertNotSame(
            NativeScript::before(1000)->enterpriseAddress(Network::Mainnet)->toBech32(),
            NativeScript::before(1001)->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
    }

    public function test_a_base_address_and_an_enterprise_address_are_different_addresses_for_one_script(): void
    {
        $script = NativeScript::sig(self::ALICE);
        $stake = Credential::keyHash(self::BOB);

        $enterprise = $script->enterpriseAddress(Network::Mainnet);
        $base = $script->baseAddress(Network::Mainnet, $stake);

        $this->assertSame($script->hashHex(), $enterprise->credential()->hex());
        $this->assertSame($script->hashHex(), $base->credential()->hex());
        $this->assertNotSame($enterprise->toBech32(), $base->toBech32());
        $this->assertSame($enterprise->toBech32(), $script->address(Network::Mainnet)->toBech32());
        $this->assertSame($base->toBech32(), $script->address(Network::Mainnet, $stake)->toBech32());
    }

    // ----------------------------------------------- what the time locks ask for

    /**
     * The direction that matters. `before` binds the interval's end and `after` binds its start, and a transaction
     * that sets neither satisfies neither: an absent bound is not an open one, because the ledger has been shown
     * nothing about when the transaction will be applied.
     */
    public static function timeLockCases(): array
    {
        return [
            // clause, slot, interval start, interval end, satisfied
            'before, interval ends earlier' => ['before', 1000, 0, 999, true],
            'before, interval ends exactly there' => ['before', 1000, 0, 1000, true],
            'before, interval ends later' => ['before', 1000, 0, 1001, false],
            'before, no end set' => ['before', 1000, 0, null, false],
            'before, only a start set to a slot below it' => ['before', 1000, 500, null, false],
            'after, interval starts later' => ['after', 1000, 1001, null, true],
            'after, interval starts exactly there' => ['after', 1000, 1000, null, true],
            'after, interval starts earlier' => ['after', 1000, 999, null, false],
            'after, no start set' => ['after', 1000, null, 5000, false],
        ];
    }

    #[DataProvider('timeLockCases')]
    public function test_a_time_lock_binds_the_end_of_the_interval_for_before_and_the_start_for_after(
        string $kind,
        int $slot,
        ?int $start,
        ?int $end,
        bool $satisfied,
    ): void {
        $script = $kind === 'before' ? NativeScript::before($slot) : NativeScript::after($slot);

        $this->assertSame($satisfied, $script->isSatisfiedBy([], $start, $end));
    }

    /**
     * The failure the direction protects against, written out. A minting policy that locks minting to before a slot
     * has to mint today and stop later. With the two swapped it does the opposite: nothing works now and everything
     * works once the campaign has ended.
     */
    public function test_a_policy_locked_with_before_mints_now_and_not_after_its_slot(): void
    {
        $expiry = 214971308;
        $policy = NativeScript::all(NativeScript::sig(self::ALICE), NativeScript::before($expiry));

        $this->assertTrue(
            $policy->isSatisfiedBy([self::ALICE], null, $expiry - 86400),
            'A policy that expires later cannot mint today.'
        );
        $this->assertFalse(
            $policy->isSatisfiedBy([self::ALICE], null, $expiry + 1),
            'A policy that expires still mints after its expiry.'
        );
        $this->assertFalse(
            $policy->isSatisfiedBy([self::ALICE], $expiry + 1, $expiry + 86400),
            'A policy that expires still mints after its expiry.'
        );
    }

    public function test_a_signature_alone_does_not_satisfy_a_clause_that_is_also_time_locked(): void
    {
        $script = NativeScript::all(NativeScript::sig(self::ALICE), NativeScript::before(1000));

        $this->assertFalse($script->isSatisfiedBy([self::ALICE], null, null));
        $this->assertFalse($script->isSatisfiedBy([], null, 999));
        $this->assertTrue($script->isSatisfiedBy([self::ALICE], null, 999));
    }

    public function test_an_any_clause_needs_one_branch_and_an_all_clause_needs_them_all(): void
    {
        $any = NativeScript::any(NativeScript::sig(self::ALICE), NativeScript::sig(self::BOB));
        $all = NativeScript::all(NativeScript::sig(self::ALICE), NativeScript::sig(self::BOB));

        $this->assertTrue($any->isSatisfiedBy([self::BOB]));
        $this->assertFalse($any->isSatisfiedBy([self::CAROL]));
        $this->assertFalse($all->isSatisfiedBy([self::BOB]));
        $this->assertTrue($all->isSatisfiedBy([self::ALICE, self::BOB]));
    }

    public function test_a_threshold_counts_the_branches_that_are_met(): void
    {
        $script = NativeScript::atLeast(
            2,
            NativeScript::sig(self::ALICE),
            NativeScript::sig(self::BOB),
            NativeScript::sig(self::CAROL),
        );

        $this->assertFalse($script->isSatisfiedBy([self::ALICE]));
        $this->assertTrue($script->isSatisfiedBy([self::ALICE, self::CAROL]));
        $this->assertTrue($script->isSatisfiedBy([self::ALICE, self::BOB, self::CAROL]));
    }

    /**
     * A threshold counts a satisfied time lock as one of its branches, which is easy to forget and is how a two of
     * three turns into a one of two once a slot passes.
     */
    public function test_a_threshold_counts_a_satisfied_time_lock_as_a_branch(): void
    {
        $script = NativeScript::atLeast(
            2,
            NativeScript::sig(self::ALICE),
            NativeScript::sig(self::BOB),
            NativeScript::after(1000),
        );

        $this->assertFalse($script->isSatisfiedBy([self::ALICE], 999, null));
        $this->assertTrue($script->isSatisfiedBy([self::ALICE], 1000, null));
    }

    // ------------------------------------------------- who can sign, and when

    public function test_a_time_lock_inside_an_all_binds_every_sibling_under_it(): void
    {
        $script = NativeScript::all(
            NativeScript::sig(self::ALICE),
            NativeScript::all(
                NativeScript::before(1000),
                NativeScript::sig(self::BOB),
            ),
        );

        $this->assertSame([], $script->unboundedSigners());
        $this->assertSame([self::ALICE, self::BOB], $script->timeBoundSigners());
        $this->assertTrue($script->isTimeBound());
    }

    public function test_a_time_lock_offered_as_one_alternative_binds_nobody(): void
    {
        $script = NativeScript::all(
            NativeScript::sig(self::ALICE),
            NativeScript::any(
                NativeScript::before(1000),
                NativeScript::sig(self::BOB),
            ),
        );

        $this->assertSame([self::ALICE, self::BOB], $script->unboundedSigners());
        $this->assertSame([], $script->timeBoundSigners());
        $this->assertFalse($script->isTimeBound());
    }

    public function test_a_threshold_binds_only_when_the_unbounded_branches_cannot_meet_it(): void
    {
        $reachable = NativeScript::atLeast(1, NativeScript::before(1000), NativeScript::sig(self::ALICE));
        $forced = NativeScript::atLeast(2, NativeScript::before(1000), NativeScript::sig(self::ALICE));

        $this->assertSame([self::ALICE], $reachable->unboundedSigners());
        $this->assertSame([], $reachable->timeBoundSigners());
        $this->assertSame([], $forced->unboundedSigners());
        $this->assertSame([self::ALICE], $forced->timeBoundSigners());
    }

    public function test_the_key_hashes_are_listed_in_the_order_the_script_names_them(): void
    {
        $script = NativeScript::any(
            NativeScript::sig(self::CAROL),
            NativeScript::all(
                NativeScript::sig(self::ALICE),
                NativeScript::before(1000),
                NativeScript::sig(self::BOB),
            ),
        );

        $this->assertSame([self::CAROL, self::ALICE, self::BOB], $script->keyHashes());
        $this->assertSame([self::CAROL], $script->unboundedSigners());
        $this->assertSame([self::ALICE, self::BOB], $script->timeBoundSigners());
    }

    // --------------------------------------------------------- the JSON form

    public function test_a_script_round_trips_through_the_json_a_script_file_holds(): void
    {
        $script = NativeScript::any(
            NativeScript::sig(self::ALICE),
            NativeScript::atLeast(
                2,
                NativeScript::sig(self::BOB),
                NativeScript::sig(self::CAROL),
                NativeScript::after(500),
            ),
            NativeScript::all(NativeScript::before(1000)),
        );

        $reread = NativeScript::fromJson($script->toJson());

        $this->assertSame($script->cborHex(), $reread->cborHex());
        $this->assertSame($script->hashHex(), $reread->hashHex());
        $this->assertSame($script->toArray(), $reread->toArray());
    }

    public function test_the_json_form_is_the_shape_cardano_cli_reads(): void
    {
        $script = NativeScript::all(NativeScript::sig(self::ALICE), NativeScript::before(1000));

        $this->assertSame([
            'type' => 'all',
            'scripts' => [
                ['type' => 'sig', 'keyHash' => self::ALICE],
                ['type' => 'before', 'slot' => 1000],
            ],
        ], $script->toArray());
    }

    // ------------------------------------------------------ the degenerate shapes

    /**
     * A container with no sub-scripts, which the grammar admits and the chain carries.
     *
     * `{"type": "all", "scripts": []}` hashes to d441227553a0f1a965fee7d60a0f724b368dd1bddbc208730fccebcf and has
     * been on mainnet since epoch 392, created by transaction
     * c6ae228099eabfebfadd325f8536e4b63ace258e3c1e1e666b89dd80a3573a4e. There is no condition left to fail, so every
     * transaction satisfies it and anyone who finds the address can spend what sits at it. cardano-cli builds it and
     * the node accepted it, so this builds it too.
     */
    public function test_an_all_with_no_sub_scripts_is_built_and_satisfied_by_a_transaction_carrying_nothing(): void
    {
        $script = NativeScript::all();

        $this->assertSame('820180', $script->cborHex());
        $this->assertSame('d441227553a0f1a965fee7d60a0f724b368dd1bddbc208730fccebcf', $script->hashHex());
        $this->assertTrue($script->isSatisfiedBy([]));
        $this->assertSame([], $script->keyHashes());
    }

    /**
     * The same script one level down, which is on preprod at
     * 60be8259acde0a72b76f36977223cac39432713a42bcfdf76a55dd7f, created by transaction
     * 976cf22d10afac84fc64895eb31c2d3142d1cfef4c7f6b1c2ff72c4ed8cafe61. The outer `all` has one sub-script, and that
     * sub-script is satisfied by a transaction carrying nothing, so the outer one is too.
     */
    public function test_an_empty_all_nested_in_another_all_is_built_and_satisfied(): void
    {
        $script = NativeScript::all(NativeScript::all());

        $this->assertSame('820181820180', $script->cborHex());
        $this->assertSame('60be8259acde0a72b76f36977223cac39432713a42bcfdf76a55dd7f', $script->hashHex());
        $this->assertTrue($script->isSatisfiedBy([]));
    }

    /**
     * An `any` with no sub-scripts is the other end of it: no branch can succeed, so nothing satisfies it and what it
     * guards cannot be spent by anybody.
     */
    public function test_an_any_with_no_sub_scripts_is_built_and_satisfied_by_nothing(): void
    {
        $script = NativeScript::any();

        $this->assertSame('820280', $script->cborHex());
        $this->assertFalse($script->isSatisfiedBy([]));
        $this->assertFalse($script->isSatisfiedBy([self::ALICE, self::BOB]));
    }

    /**
     * A threshold of zero is met before any sub-script is counted, which makes the sub-scripts beside it decoration.
     */
    public function test_a_threshold_of_zero_is_built_and_met_by_no_signatures(): void
    {
        $script = NativeScript::atLeast(0, NativeScript::sig(self::ALICE));

        $this->assertSame('830300818200581c'.self::ALICE, $script->cborHex());
        $this->assertTrue($script->isSatisfiedBy([]));
    }

    /**
     * And a negative threshold, which the CDDL admits because `n` in `script_n_of_k` is a signed 64-bit integer.
     * cardano-cli reads one out of a script file, so a script file holding one has to arrive here intact: written as
     * a CBOR negative integer, read back as the number it was, and hashed to the same twenty-eight bytes either way.
     */
    public function test_a_negative_threshold_is_built_and_written_as_a_negative_integer(): void
    {
        $script = NativeScript::fromArray([
            'type' => 'atLeast',
            'required' => -1,
            'scripts' => [['type' => 'sig', 'keyHash' => self::ALICE]],
        ]);

        $this->assertSame('830320818200581c'.self::ALICE, $script->cborHex());
        $this->assertSame(-1, $script->toArray()['required']);
        $this->assertTrue($script->isSatisfiedBy([]));
        $this->assertSame($script->hashHex(), NativeScript::fromCbor($script->cbor())->hashHex());
    }

    // -------------------------------------------------- how a container is framed

    /**
     * The ledger's own encoder writes an array of up to 23 items definite in length and everything above that
     * indefinite, with a break byte at the end. cardano-node and cardano-cli serialize through that encoder, so a
     * container built here is framed the same way and the hash a caller derives from a script file is the hash
     * cardano-cli prints for that file.
     */
    public function test_a_container_of_twenty_three_is_definite_and_one_of_twenty_four_is_indefinite(): void
    {
        $sig = NativeScript::sig(self::ALICE);
        $sigHex = '8200581c'.self::ALICE;

        $this->assertSame(
            '820197'.str_repeat($sigHex, 23),
            NativeScript::all(...array_fill(0, 23, $sig))->cborHex()
        );
        $this->assertSame(
            '82019f'.str_repeat($sigHex, 24).'ff',
            NativeScript::all(...array_fill(0, 24, $sig))->cborHex()
        );
        $this->assertSame(
            '830318189f'.str_repeat($sigHex, 24).'ff',
            NativeScript::atLeast(24, ...array_fill(0, 24, $sig))->cborHex()
        );
    }

    /**
     * The framing is per container rather than per script, so a narrow container holding a wide one keeps its own
     * definite head. A script that reframed every container at once would agree with cardano-cli here and disagree
     * one level up.
     */
    public function test_only_the_container_that_is_wide_changes_its_framing(): void
    {
        $sig = NativeScript::sig(self::ALICE);
        $sigHex = '8200581c'.self::ALICE;

        $this->assertSame(
            '820181'.'82029f'.str_repeat($sigHex, 24).'ff',
            NativeScript::all(NativeScript::any(...array_fill(0, 24, $sig)))->cborHex()
        );
    }

    /**
     * The same container framed definite is a script the ledger accepts, hashes to a different twenty-eight bytes,
     * and is not what this package writes. Re-encoding it here would move its hash, so it is refused with a message
     * saying where to get the hash instead.
     */
    public function test_a_wide_container_framed_definite_is_refused_rather_than_re_framed(): void
    {
        $sigHex = '8200581c'.self::ALICE;
        $wide = NativeScript::all(...array_fill(0, 24, NativeScript::sig(self::ALICE)));
        $definite = (string) hex2bin('82019818'.str_repeat($sigHex, 24));

        $this->assertNotSame($wide->hashHex(), bin2hex(Blake2b::hash224("\x00".$definite)));

        $this->expectException(ScriptException::class);
        $this->expectExceptionMessage('re-encoding it would change');

        NativeScript::fromCbor($definite);
    }

    // ------------------------------------------------------------- bad inputs

    public static function unusableKeyHashes(): array
    {
        return [
            'empty' => [''],
            'one nibble short' => ['000102030405060708090a0b0c0d0e0f101112131415161718191a1'],
            'one nibble long' => ['000102030405060708090a0b0c0d0e0f101112131415161718191a1bc'],
            'a 32 byte hash pasted in by mistake' => [str_repeat('ab', 32)],
            'uppercase' => ['000102030405060708090A0B0C0D0E0F101112131415161718191A1B'],
            'not hex' => ['zz0102030405060708090a0b0c0d0e0f101112131415161718191a1b'],
            'with an 0x prefix' => ['0x0102030405060708090a0b0c0d0e0f101112131415161718191a1b'],
            'whitespace around a good hash' => [' 000102030405060708090a0b0c0d0e0f101112131415161718191a1b '],
        ];
    }

    #[DataProvider('unusableKeyHashes')]
    public function test_an_unusable_key_hash_is_refused_rather_than_padded(string $keyHash): void
    {
        $this->expectException(AddressException::class);

        NativeScript::sig($keyHash);
    }

    public function test_a_script_hash_cannot_be_passed_off_as_a_signer(): void
    {
        $this->expectException(ScriptException::class);

        NativeScript::signedBy(Credential::scriptHash(self::ALICE));
    }

    public function test_a_negative_slot_is_refused(): void
    {
        $this->expectException(ScriptException::class);

        NativeScript::before(-1);
    }

    /**
     * The one degenerate shape the whole ecosystem refuses.
     *
     * A threshold above the number of sub-scripts beside it can never be met, and cardano-cli refuses it with
     * "Required number of script signatures exceeds the number of scripts" rather than building it.
     */
    public function test_an_unreachable_threshold_is_refused(): void
    {
        $this->expectException(ScriptException::class);
        $this->expectExceptionMessage('exceeds the number of scripts');

        NativeScript::atLeast(3, NativeScript::sig(self::ALICE), NativeScript::sig(self::BOB));
    }

    public static function unusableJson(): array
    {
        return [
            'no type' => [['keyHash' => self::ALICE]],
            'a type nobody defined' => [['type' => 'RequireSignature', 'keyHash' => self::ALICE]],
            'a sig with no key hash' => [['type' => 'sig']],
            'a before with no slot' => [['type' => 'before']],
            'a slot written as a string' => [['type' => 'before', 'slot' => '1000']],
            'an all with no scripts' => [['type' => 'all']],
            'an atLeast with no threshold' => [['type' => 'atLeast', 'scripts' => [['type' => 'sig', 'keyHash' => self::ALICE]]]],
            'a scripts list holding a scalar' => [['type' => 'any', 'scripts' => ['sig']]],
        ];
    }

    #[DataProvider('unusableJson')]
    public function test_json_that_is_not_a_native_script_is_refused(array $json): void
    {
        $this->expectException(ScriptException::class);

        NativeScript::fromArray($json);
    }

    public static function unusableCbor(): array
    {
        return [
            'not CBOR at all' => ['ff'],
            'an integer rather than an array' => ['01'],
            'an empty array' => ['80'],
            'a constructor nobody defined' => ['8206'],
            'a sig with a 32 byte hash' => ['82005820'.str_repeat('ab', 32)],
            'a sig with a slot' => ['8200190100'],
            'an all with a third item' => ['8301818200581c'.self::ALICE.'00'],
            'an atLeast with only two items' => ['8203818200581c'.self::ALICE],
            'a negative slot' => ['820520'],
            'bytes following the script' => ['8200581c'.self::ALICE.'00'],
            'an indefinite length array' => ['9f0058'.'1c'.self::ALICE.'ff'],
        ];
    }

    #[DataProvider('unusableCbor')]
    public function test_cbor_this_package_would_not_have_written_is_refused(string $hex): void
    {
        $this->expectException(ScriptException::class);

        NativeScript::fromCbor((string) hex2bin($hex));
    }

    /**
     * A script written with a wider integer head than it needs is still a script the ledger accepted, and its hash is
     * the hash of the bytes it arrived in. Re-encoding it here would produce a different hash, so it is refused with
     * a message that says where to get the hash instead, rather than quietly rewritten.
     */
    public function test_a_script_written_with_a_wider_head_than_it_needs_is_refused_rather_than_rewritten(): void
    {
        $canonical = NativeScript::before(100);
        $wider = (string) hex2bin('8205'.'190064');

        $this->assertSame('82051864', $canonical->cborHex());
        $this->assertNotSame(
            $canonical->hashHex(),
            bin2hex(Blake2b::hash224("\x00".$wider)),
        );

        $this->expectException(ScriptException::class);
        $this->expectExceptionMessage('re-encoding it would change');

        NativeScript::fromCbor($wider);
    }
}
