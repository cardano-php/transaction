<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Time;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\TimeException;
use Cardano\Transaction\Ledger\Slot;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The network's eras, and the conversion between wall clock time and slot numbers.
 *
 * A slot number is not a fixed number of seconds from genesis. Byron slots were twenty seconds long and everything
 * since Shelley has been one second, so a converter that assumed either figure is wrong on one side of the Shelley
 * boundary and would stay wrong silently. The ledger publishes the eras and their slot lengths; this reads them.
 *
 * Two things about the source are worth knowing. Ogmios writes era times as seconds since the network's system start
 * rather than as Unix time, so the system start has to be read alongside them. And Koios's own `/era_summaries` is a
 * list of hard forks and protocol versions that carries neither slot lengths nor era start slots, so it cannot be
 * used for this; the summaries here come from Ogmios, where they are the ledger's own.
 *
 * The final era's end is a forecast horizon, not a date the era is known to finish on. It marks how far ahead the
 * ledger will commit to the current slot length, usually a few days. A campaign expiry three months out is always
 * past it, so a conversion beyond the horizon is answered rather than refused, using the current era's parameters,
 * and isBeyondHorizon() says when an answer rests on that assumption. The assumption is that no hard fork changes the
 * slot length before then, which has held for every Cardano era since Shelley.
 */
final class EraSummaries
{
    /**
     * @param  list<EraSummary>  $eras
     */
    private function __construct(
        public readonly int $systemStart,
        private readonly array $eras,
    ) {}

    /**
     * The result of an Ogmios queryLedgerState/eraSummaries query, together with the network's system start.
     *
     * @param  list<array<string, mixed>>  $summaries
     */
    public static function fromOgmios(array $summaries, DateTimeInterface|int|string $systemStart): self
    {
        $start = self::systemStart($systemStart);
        $eras = [];

        foreach (array_values($summaries) as $index => $entry) {
            if (! is_array($entry)) {
                throw new TimeException(sprintf('Era summary %d is not an object.', $index));
            }

            $eras[] = EraSummary::fromOgmios($entry, $start, $index);
        }

        if ($eras === []) {
            throw new TimeException('A network has at least one era.');
        }

        $previous = null;
        foreach ($eras as $index => $era) {
            if ($previous !== null && ($era->startSlot < $previous->startSlot || $era->startTime < $previous->startTime)) {
                throw new TimeException(sprintf('Era summary %d starts before the one before it.', $index));
            }

            $previous = $era;
        }

        return new self($start, $eras);
    }

    /**
     * @return list<EraSummary>
     */
    public function eras(): array
    {
        return $this->eras;
    }

    public function current(): EraSummary
    {
        return $this->eras[count($this->eras) - 1];
    }

    /**
     * The instant the ledger will no longer commit to the current slot length beyond.
     */
    public function horizonTime(): ?int
    {
        return $this->current()->endTime;
    }

    public function isBeyondHorizon(DateTimeInterface|int $time): bool
    {
        $horizon = $this->horizonTime();

        return $horizon !== null && self::unix($time) >= $horizon;
    }

    /**
     * The slot a wall clock instant falls on.
     *
     * An instant that lands between two slots is refused rather than rounded to either side. See EraSummary::slotAt().
     */
    public function slotAt(DateTimeInterface|int $time): int
    {
        return $this->eraForTime(self::unix($time))->slotAt(self::unix($time));
    }

    /**
     * The instant a slot begins.
     *
     * A slot runs to 2^64-1, which is past what a PHP integer holds, so one above 2^63-1 is given as a decimal string
     * or as a BigInteger. The instants at that end of the range are past what a PHP integer holds as well, and are
     * refused rather than narrowed to a date that is not the one asked about.
     */
    public function timeOfSlot(int|string|BigInteger $slot): int
    {
        $value = self::slot($slot);

        return $this->eraForSlot($value)->timeOfSlot($value);
    }

    public function eraForTime(DateTimeInterface|int $time): EraSummary
    {
        $unix = self::unix($time);

        if ($unix < $this->eras[0]->startTime) {
            throw new TimeException('That instant falls before the network began.');
        }

        foreach ($this->eras as $era) {
            if ($era->containsTime($unix)) {
                return $era;
            }
        }

        // Past the forecast horizon, which is where every campaign expiry sits. The current era's parameters are the
        // only ones there are; a later hard fork that changed the slot length would move this answer.
        return $this->current();
    }

    public function eraForSlot(int|string|BigInteger $slot): EraSummary
    {
        $value = self::slot($slot);

        if ($value->isLessThan($this->eras[0]->startSlot)) {
            throw new TimeException('That slot falls before the network began.');
        }

        foreach ($this->eras as $era) {
            if ($era->containsSlot($value)) {
                return $era;
            }
        }

        return $this->current();
    }

    private static function unix(DateTimeInterface|int $time): int
    {
        return is_int($time) ? $time : $time->getTimestamp();
    }

    /**
     * A slot as the number it is, refusing anything outside the range a slot takes.
     */
    private static function slot(int|string|BigInteger $slot): BigInteger
    {
        return Slot::parse($slot) ?? throw new TimeException(Slot::complaint($slot));
    }

    private static function systemStart(DateTimeInterface|int|string $systemStart): int
    {
        if (is_int($systemStart)) {
            return $systemStart;
        }

        if ($systemStart instanceof DateTimeInterface) {
            return $systemStart->getTimestamp();
        }

        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s\Z',
            $systemStart,
            new DateTimeZone('UTC')
        );

        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $systemStart) {
            throw new TimeException('A system start is written as 2017-09-23T21:44:51Z, got: '.$systemStart);
        }

        return $parsed->getTimestamp();
    }
}
