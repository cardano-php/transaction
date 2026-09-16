<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Time;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\TimeException;
use Cardano\Transaction\Ledger\Slot;

/**
 * One era's worth of slot arithmetic: where it starts, and how long a slot lasts inside it.
 *
 * Times in an Ouroboros era summary are seconds since the network's system start, not Unix time. They are converted
 * once, when the summaries are read, so nothing downstream has to remember which of the two it is holding.
 *
 * Slot length is kept in milliseconds because that is the unit the ledger publishes it in. Every era Cardano has had
 * is either twenty seconds or one second, but an era with a fractional second slot would silently change every
 * conversion if the value were rounded into seconds on the way in.
 */
final class EraSummary
{
    private function __construct(
        public readonly int $startTime,
        public readonly int $startSlot,
        public readonly int $startEpoch,
        public readonly ?int $endTime,
        public readonly ?int $endSlot,
        public readonly int $slotLengthMs,
        public readonly int $epochLength,
    ) {}

    /**
     * One entry of an Ogmios queryLedgerState/eraSummaries reply.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromOgmios(array $entry, int $systemStart, int $index): self
    {
        $start = self::bound($entry, 'start', $index, $systemStart, required: true);
        $end = self::bound($entry, 'end', $index, $systemStart, required: false);
        $parameters = $entry['parameters'] ?? null;

        if (! is_array($parameters)) {
            throw new TimeException(sprintf('Era summary %d carries no parameters.', $index));
        }

        $slotLengthMs = self::whole($parameters['slotLength']['milliseconds'] ?? null, $index, 'slot length');
        $epochLength = self::whole($parameters['epochLength'] ?? null, $index, 'epoch length');

        if ($slotLengthMs < 1) {
            throw new TimeException(sprintf('Era summary %d has a slot length of %d ms.', $index, $slotLengthMs));
        }

        return new self(
            $start['time'],
            $start['slot'],
            $start['epoch'],
            $end['time'] ?? null,
            $end['slot'] ?? null,
            $slotLengthMs,
            $epochLength,
        );
    }

    public function slotLengthSeconds(): float
    {
        return $this->slotLengthMs / 1000;
    }

    public function containsTime(int $unixTime): bool
    {
        return $unixTime >= $this->startTime && ($this->endTime === null || $unixTime < $this->endTime);
    }

    public function containsSlot(int|string|BigInteger $slot): bool
    {
        $value = self::slot($slot);

        return $value->isGreaterThanOrEqualTo($this->startSlot)
            && ($this->endSlot === null || $value->isLessThan($this->endSlot));
    }

    /**
     * The slot an instant falls on, refusing an instant that falls between two slots.
     *
     * Rounding here would move the expiry of a script, and with it the address the script pays to, by however much it
     * rounded. There is no way to notice that afterwards and no way to correct it, so an instant that is not a slot
     * boundary is refused instead.
     */
    public function slotAt(int $unixTime): int
    {
        $elapsedMs = BigInteger::of($unixTime)->minus($this->startTime)->multipliedBy(1000);

        if ($elapsedMs->isNegative()) {
            throw new TimeException('That instant falls before this era began.');
        }

        if (! $elapsedMs->mod($this->slotLengthMs)->isZero()) {
            throw new TimeException(sprintf(
                'That instant is %s ms into an era whose slots are %d ms long, so it falls between two slots.',
                $elapsedMs,
                $this->slotLengthMs
            ));
        }

        return self::asPhpInt($elapsedMs->dividedBy($this->slotLengthMs)->plus($this->startSlot), 'slot');
    }

    /**
     * The instant a slot begins.
     *
     * The multiplication is done in full width rather than in a PHP integer. A slot in the upper part of the range
     * times a slot length overflows a signed 64-bit integer, and PHP answers an overflowed multiplication with a
     * float rather than an error, so the arithmetic would quietly continue against a number that had lost its low
     * bits. There are slots for which it comes back out looking like an ordinary answer.
     */
    public function timeOfSlot(int|string|BigInteger $slot): int
    {
        $value = self::slot($slot);

        if ($value->isLessThan($this->startSlot)) {
            throw new TimeException('That slot falls before this era began.');
        }

        $elapsedMs = $value->minus($this->startSlot)->multipliedBy($this->slotLengthMs);

        if (! $elapsedMs->mod(1000)->isZero()) {
            throw new TimeException(sprintf(
                'Slot %s begins %s ms after the era started, which is not a whole second.',
                $value,
                $elapsedMs
            ));
        }

        return self::asPhpInt($elapsedMs->dividedBy(1000)->plus($this->startTime), 'instant');
    }

    /**
     * @return array{time: int|null, slot: int|null, epoch: int|null}
     */
    private static function bound(array $entry, string $which, int $index, int $systemStart, bool $required): array
    {
        $bound = $entry[$which] ?? null;

        if (! is_array($bound)) {
            if ($required) {
                throw new TimeException(sprintf('Era summary %d carries no %s bound.', $index, $which));
            }

            return ['time' => null, 'slot' => null, 'epoch' => null];
        }

        return [
            'time' => $systemStart + self::whole($bound['time']['seconds'] ?? null, $index, $which.' time'),
            'slot' => self::whole($bound['slot'] ?? null, $index, $which.' slot'),
            'epoch' => self::whole($bound['epoch'] ?? null, $index, $which.' epoch'),
        ];
    }

    /**
     * A whole number, refusing a fractional one and refusing a negative one.
     *
     * JSON has one number type and providers write these as floats. A fraction here is not a rounding nuisance, it is
     * a value this arithmetic cannot represent, and pretending otherwise moves every slot derived from it.
     *
     * Every quantity read out of an era summary counts something: an offset in seconds from the system start, a slot,
     * an epoch, a slot length, an epoch length. None of them can be below zero, and one that is would put an era
     * before the network it belongs to.
     */
    private static function whole(mixed $value, int $index, string $what): int
    {
        $whole = null;

        if (is_int($value)) {
            $whole = $value;
        } elseif (is_float($value) && $value === floor($value) && abs($value) < (float) PHP_INT_MAX) {
            $whole = (int) $value;
        }

        if ($whole === null) {
            throw new TimeException(sprintf(
                'Era summary %d has a %s of %s, which is not a whole number.',
                $index,
                $what,
                var_export($value, true)
            ));
        }

        if ($whole < 0) {
            throw new TimeException(sprintf(
                'Era summary %d has a %s of %d, which counts nothing.',
                $index,
                $what,
                $whole
            ));
        }

        return $whole;
    }

    /**
     * A slot as the number it is, refusing anything outside the range a slot takes.
     */
    private static function slot(int|string|BigInteger $slot): BigInteger
    {
        return Slot::parse($slot) ?? throw new TimeException(Slot::complaint($slot));
    }

    /**
     * A result narrowed back to a PHP integer, refusing one that does not fit.
     *
     * Slots run to 2^64-1 and PHP's integer stops at 2^63-1, so the far end of the slot range names instants no PHP
     * integer can hold. Saying so is the only honest answer; narrowing one would be a date.
     */
    private static function asPhpInt(BigInteger $value, string $what): int
    {
        if ($value->isGreaterThan(PHP_INT_MAX)) {
            throw new TimeException(sprintf(
                'That %s is past %d, which is as far as this platform counts.',
                $what,
                PHP_INT_MAX
            ));
        }

        return $value->toInt();
    }
}
