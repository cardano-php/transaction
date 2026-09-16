<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Exception\TimeException;
use Cardano\Transaction\Time\EraSummaries;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wall clock time to slot numbers, checked against blocks the chain already dated.
 *
 * The chain reports both a timestamp and an absolute slot for every block, which makes each one a conversion whose
 * answer was decided by the network rather than here. Ten of them are recorded, across both networks and every era
 * boundary the arithmetic can get wrong, and none of the recorded values came from this code. See
 * tests/fixtures/cardano-time/README.md.
 */
class EraSummariesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return JsonFixture::read('cardano-time/era-summaries.json');
    }

    private static function eras(string $network): EraSummaries
    {
        $recorded = self::fixture()['networks'][$network];

        return EraSummaries::fromOgmios($recorded['era_summaries'], $recorded['system_start']);
    }

    public static function knownBlocks(): array
    {
        $cases = [];

        foreach (self::fixture()['networks'] as $network => $recorded) {
            foreach ($recorded['blocks'] as $block) {
                $cases[$network.' block '.$block['block_height']] = [$network, $block];
            }
        }

        return $cases;
    }

    /**
     * The assertion the fixture exists for.
     */
    #[DataProvider('knownBlocks')]
    public function test_a_known_blocks_timestamp_converts_to_the_slot_the_chain_reports(string $network, array $block): void
    {
        $this->assertSame(
            $block['abs_slot'],
            self::eras($network)->slotAt($block['block_time']),
            sprintf('%s block %d converts to the wrong slot.', $network, $block['block_height'])
        );
    }

    #[DataProvider('knownBlocks')]
    public function test_a_known_blocks_slot_converts_back_to_the_timestamp_the_chain_reports(string $network, array $block): void
    {
        $this->assertSame(
            $block['block_time'],
            self::eras($network)->timeOfSlot($block['abs_slot']),
            sprintf('%s block %d converts back to the wrong time.', $network, $block['block_height'])
        );
    }

    /**
     * The fixture is only worth what it straddles. A set of blocks that all sat inside one era would pass every
     * assertion above and say nothing about the era lookup.
     */
    public function test_the_known_blocks_straddle_the_byron_boundary_on_mainnet(): void
    {
        $eras = self::eras('mainnet');
        $lengths = [];

        foreach (self::fixture()['networks']['mainnet']['blocks'] as $block) {
            $lengths[$eras->eraForSlot($block['abs_slot'])->slotLengthMs] = true;
        }

        $this->assertSame([20000, 1000], array_keys($lengths));
    }

    public function test_a_byron_slot_is_twenty_seconds_and_a_shelley_slot_is_one(): void
    {
        $eras = self::eras('mainnet');

        $this->assertSame(20000, $eras->eras()[0]->slotLengthMs);
        $this->assertSame(20.0, $eras->eras()[0]->slotLengthSeconds());
        $this->assertSame(1000, $eras->current()->slotLengthMs);
        $this->assertSame(1.0, $eras->current()->slotLengthSeconds());
    }

    /**
     * Mainnet's Shelley era began at slot 4492800 at 2020-07-29T21:44:51Z, which is the anchor every campaign expiry
     * is measured from. The first Shelley block is in the fixture, so the boundary is checked from both sides.
     */
    public function test_the_shelley_boundary_is_where_the_chain_says_it_is(): void
    {
        $eras = self::eras('mainnet');

        $this->assertSame(4492800, $eras->slotAt(1596059091));
        $this->assertSame(1596059091, $eras->timeOfSlot(4492800));
        $this->assertSame(1000, $eras->eraForSlot(4492800)->slotLengthMs);
        $this->assertSame(20000, $eras->eraForSlot(4492799)->slotLengthMs);

        // One Byron slot earlier is twenty seconds earlier, not one.
        $this->assertSame(1596059091 - 20, $eras->timeOfSlot(4492799));
    }

    public function test_the_system_start_is_the_first_slot_of_the_first_era(): void
    {
        foreach (self::fixture()['networks'] as $network => $recorded) {
            $eras = self::eras($network);

            $this->assertSame($recorded['system_start_unix'], $eras->systemStart);
            $this->assertSame(0, $eras->slotAt($recorded['system_start_unix']));
            $this->assertSame($recorded['system_start_unix'], $eras->timeOfSlot(0));
        }
    }

    public function test_an_instant_before_the_network_began_is_refused(): void
    {
        $this->expectException(TimeException::class);

        self::eras('mainnet')->slotAt(self::fixture()['networks']['mainnet']['system_start_unix'] - 1);
    }

    public function test_a_slot_before_the_network_began_is_refused(): void
    {
        $this->expectException(TimeException::class);

        self::eras('mainnet')->timeOfSlot(-1);
    }

    /**
     * A slot is the same uint64 here as it is in a script, and it is converted at full width.
     *
     * A slot times a slot length passes 2^63-1 long before the slot itself does, and PHP answers an overflowed
     * multiplication with a float rather than an error, so the arithmetic would carry on against a number that had
     * lost its low bits. The two slots below are where that showed. The first is the distance at which the wrapped
     * product came back as zero, which is the era's own start time for an instant seventy-three billion years after
     * it; the second fell over inside intdiv, because the float never narrowed back.
     */
    public function test_a_slot_whose_arithmetic_passes_a_php_integer_converts_exactly(): void
    {
        $eras = self::eras('mainnet');
        $era = $eras->current();

        $this->assertSame(1000, $era->slotLengthMs);
        $this->assertSame($era->startTime + (1 << 61), $eras->timeOfSlot($era->startSlot + (1 << 61)));
        $this->assertSame(
            $era->startTime + (4611686018427387904 - $era->startSlot),
            $eras->timeOfSlot(4611686018427387904)
        );
    }

    /**
     * Where it genuinely cannot answer, it says so.
     *
     * Slots run to 2^64-1 and a PHP integer stops at 2^63-1, so the top of the slot range names instants no PHP
     * integer holds. Narrowing one would be a date, and a date is what a caller would then act on.
     */
    public function test_a_slot_whose_instant_is_past_what_a_php_integer_holds_is_refused(): void
    {
        $this->expectException(TimeException::class);

        self::eras('mainnet')->timeOfSlot('18446744073709551615');
    }

    public function test_a_slot_past_the_top_of_the_range_is_not_a_slot(): void
    {
        $this->expectException(TimeException::class);

        self::eras('mainnet')->timeOfSlot('18446744073709551616');
    }

    /**
     * An ordinary slot given as a decimal string, which is how one above 2^63-1 has to arrive.
     */
    public function test_a_slot_given_as_a_decimal_string_converts_to_the_same_instant(): void
    {
        $eras = self::eras('mainnet');

        $this->assertSame($eras->timeOfSlot(150000000), $eras->timeOfSlot('150000000'));
        $this->assertSame($eras->eraForSlot(150000000), $eras->eraForSlot('150000000'));
    }

    /**
     * Every number in an era summary counts something, so none of them can be below zero.
     */
    public function test_an_era_summary_with_a_negative_quantity_is_refused(): void
    {
        $era = [
            'start' => ['time' => ['seconds' => 0], 'slot' => -1, 'epoch' => 0],
            'end' => null,
            'parameters' => ['epochLength' => 21600, 'slotLength' => ['milliseconds' => 1000]],
        ];

        $this->expectException(TimeException::class);

        EraSummaries::fromOgmios([$era], 1506203091);
    }

    /**
     * Byron slots are twenty seconds long, so nineteen instants out of twenty in that era fall between two slots.
     * Rounding either way would move a script's expiry, and with it its address, so they are refused.
     */
    public function test_an_instant_that_falls_between_two_slots_is_refused(): void
    {
        $eras = self::eras('mainnet');
        $start = self::fixture()['networks']['mainnet']['system_start_unix'];

        $this->assertSame(1, $eras->slotAt($start + 20));

        $this->expectException(TimeException::class);

        $eras->slotAt($start + 21);
    }

    public function test_an_instant_can_be_given_as_a_date_time(): void
    {
        $eras = self::eras('mainnet');
        $instant = new DateTimeImmutable('2027-03-31 23:59:59', new DateTimeZone('UTC'));

        $this->assertSame(214971308, $eras->slotAt($instant));
        $this->assertSame($eras->slotAt(1806537599), $eras->slotAt($instant));
    }

    /**
     * A date time in another zone names the same instant, and has to give the same slot. A conversion that read the
     * local wall clock rather than the instant would be wrong by the offset, silently, on one server and not another.
     */
    public function test_the_same_instant_in_another_timezone_gives_the_same_slot(): void
    {
        $eras = self::eras('mainnet');
        $utc = new DateTimeImmutable('2027-03-31 23:59:59', new DateTimeZone('UTC'));
        $kathmandu = new DateTimeImmutable('2027-04-01 05:44:59', new DateTimeZone('Asia/Kathmandu'));

        $this->assertSame($utc->getTimestamp(), $kathmandu->getTimestamp());
        $this->assertSame($eras->slotAt($utc), $eras->slotAt($kathmandu));
    }

    // ------------------------------------------------------- the horizon

    /**
     * The last era's end is a forecast horizon rather than a date the era is known to finish on. Every campaign
     * expiry is months past it, so a conversion beyond it has to be answered, and the caller has to be able to find
     * out that it rests on no further hard fork changing the slot length.
     */
    public function test_a_campaign_expiry_falls_beyond_the_forecast_horizon_and_is_still_converted(): void
    {
        $eras = self::eras('mainnet');
        $expiry = 1806537599;

        $this->assertNotNull($eras->horizonTime());
        $this->assertLessThan($expiry, $eras->horizonTime());
        $this->assertTrue($eras->isBeyondHorizon($expiry));
        $this->assertSame(214971308, $eras->slotAt($expiry));
        $this->assertSame($eras->current(), $eras->eraForTime($expiry));
    }

    public function test_an_instant_inside_the_horizon_is_not_reported_as_beyond_it(): void
    {
        $eras = self::eras('mainnet');

        $this->assertFalse($eras->isBeyondHorizon($eras->horizonTime() - 1));
        $this->assertTrue($eras->isBeyondHorizon($eras->horizonTime()));
    }

    // ------------------------------------------------- what the source has to be

    /**
     * Koios has an endpoint called /era_summaries and it is not this one. It lists hard forks with protocol versions
     * and first block times, and carries neither a slot length nor an era's start slot, so nothing can be converted
     * from it. Handing it to this class has to fail rather than produce a number.
     */
    public function test_koioss_own_era_summaries_endpoint_is_not_a_source_for_this(): void
    {
        $hardForkList = [
            [
                'era' => 'Shelley',
                'protocol_major' => 2,
                'epoch_no' => 208,
                'first_block_time' => 1596059091.0,
                'first_block_hash' => 'aa83acbf5904c0edfe4d79b3689d3d00fcfc553cf360fd2229b98d464c28e9de',
            ],
        ];

        $this->expectException(TimeException::class);

        EraSummaries::fromOgmios($hardForkList, 1506203091);
    }

    public static function unusableSummaries(): array
    {
        $era = [
            'start' => ['time' => ['seconds' => 0], 'slot' => 0, 'epoch' => 0],
            'end' => ['time' => ['seconds' => 1000], 'slot' => 1000, 'epoch' => 1],
            'parameters' => ['epochLength' => 21600, 'slotLength' => ['milliseconds' => 1000], 'safeZone' => 4320],
        ];

        $noParameters = $era;
        unset($noParameters['parameters']);

        $noStart = $era;
        unset($noStart['start']);

        $fractionalSlot = $era;
        $fractionalSlot['parameters']['slotLength']['milliseconds'] = 1000.5;

        $zeroSlot = $era;
        $zeroSlot['parameters']['slotLength']['milliseconds'] = 0;

        $fractionalTime = $era;
        $fractionalTime['start']['time']['seconds'] = 0.5;

        return [
            'no eras at all' => [[]],
            'an era with no parameters' => [[$noParameters]],
            'an era with no start bound' => [[$noStart]],
            'a slot length that is not whole milliseconds' => [[$fractionalSlot]],
            'a slot length of nothing' => [[$zeroSlot]],
            'a start time that is not a whole second' => [[$fractionalTime]],
            'eras out of order' => [[
                ['start' => ['time' => ['seconds' => 1000], 'slot' => 1000, 'epoch' => 1], 'end' => null, 'parameters' => $era['parameters']],
                ['start' => ['time' => ['seconds' => 0], 'slot' => 0, 'epoch' => 0], 'end' => null, 'parameters' => $era['parameters']],
            ]],
        ];
    }

    #[DataProvider('unusableSummaries')]
    public function test_an_unusable_era_summary_is_refused(array $summaries): void
    {
        $this->expectException(TimeException::class);

        EraSummaries::fromOgmios($summaries, 1506203091);
    }

    public static function unusableSystemStarts(): array
    {
        return [
            'empty' => [''],
            'a date with no time' => ['2017-09-23'],
            'a fractional second' => ['2017-09-23T21:44:51.000Z'],
            'an offset rather than Z' => ['2017-09-23T21:44:51+00:00'],
            'words' => ['when Byron began'],
        ];
    }

    #[DataProvider('unusableSystemStarts')]
    public function test_an_unusable_system_start_is_refused(string $systemStart): void
    {
        $this->expectException(TimeException::class);

        EraSummaries::fromOgmios(self::fixture()['networks']['mainnet']['era_summaries'], $systemStart);
    }

    /**
     * Era times are seconds since the system start rather than Unix time. Reading them as Unix time would put every
     * era in 1970 and every conversion out by fifty years, so the system start has to reach the arithmetic.
     */
    public function test_the_system_start_is_added_to_the_era_times_rather_than_ignored(): void
    {
        $recorded = self::fixture()['networks']['mainnet'];
        $shifted = EraSummaries::fromOgmios($recorded['era_summaries'], $recorded['system_start_unix'] + 1000);

        $this->assertSame(
            self::eras('mainnet')->slotAt(1806537599) - 1000,
            $shifted->slotAt(1806537599),
        );
    }

    /**
     * The two networks number their slots differently for the same instant, which is the mistake a single hardcoded
     * anchor would make invisible.
     */
    public function test_the_two_networks_give_different_slots_for_the_same_instant(): void
    {
        $instant = 1806537599;

        $this->assertNotSame(
            self::eras('mainnet')->slotAt($instant),
            self::eras('preprod')->slotAt($instant),
        );
    }
}
