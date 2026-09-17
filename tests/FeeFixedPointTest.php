<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Cardano\Transaction\Cbor\CborCodec;
use Cardano\Transaction\Cbor\CborInteger;
use Cardano\Transaction\Cbor\CborValue;
use Cardano\Transaction\Cbor\MapForm;
use Cardano\Transaction\Cbor\SequenceForm;
use Cardano\Transaction\Exception\ArithmeticException;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\FeeFixedPoint;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Ledger\WitnessPlan;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Primitives\VkeyWitness;
use PHPUnit\Framework\TestCase;

/**
 * The fee that is inside the thing it is charged on.
 *
 * Every case here renders a real transaction at a candidate fee and measures the bytes, because the failure this
 * guards against only exists in the bytes: at 65535 lovelace the fee field is three bytes and at 65536 it is five,
 * and a builder that computes the fee once lands on the wrong side of that about as often as its fees happen to
 * straddle it.
 */
class FeeFixedPointTest extends TestCase
{
    private function parameters(): LedgerParameters
    {
        return LedgerFixtures::parameters();
    }

    /**
     * A transaction of the shape this platform builds: one input, a payment output, a change output, one signature.
     *
     * @param  callable(Natural): CborInteger  $feeField  how the fee is written, narrow or padded
     * @return callable(Natural): string
     */
    private function renderer(int $outputs = 2, int $signatures = 1, ?callable $feeField = null): callable
    {
        $feeField ??= static fn (Natural $fee): CborInteger => CborInteger::of($fee->value);

        $address = "\x01".str_repeat("\x11", 28).str_repeat("\x22", 28);

        $input = SequenceForm::definite()->wrap([
            CborValue::byteString(str_repeat("\x33", 32)),
            CborInteger::of(0)->toCbor(),
        ]);

        $outputObjects = [];
        for ($i = 0; $i < $outputs; $i++) {
            $outputObjects[] = TransactionOutput::create($address, Value::lovelace((string) (5_000_000 + $i)))
                ->toCbor();
        }

        return static function (Natural $fee) use ($input, $outputObjects, $feeField, $signatures): string {
            $body = MapForm::definite()->wrap([
                [CborInteger::of(0)->toCbor(), SequenceForm::definite()->wrap([$input])],
                [CborInteger::of(1)->toCbor(), SequenceForm::definite()->wrap($outputObjects)],
                [CborInteger::of(2)->toCbor(), $feeField($fee)->toCbor()],
            ]);

            $witnesses = WitnessPlan::forSignatures($signatures)->dummyWitnesses();

            $witnessSet = MapForm::definite()->wrap([[
                CborInteger::of(0)->toCbor(),
                SequenceForm::definite()->wrap(
                    array_map(static fn (VkeyWitness $w): CborValue => $w->toCbor(), $witnesses)
                ),
            ]]);

            return CborCodec::encode(SequenceForm::definite()->wrap([
                $body,
                $witnessSet,
                CborValue::bool(true),
                CborValue::null(),
            ]));
        };
    }

    public function test_the_settled_fee_covers_the_transaction_that_carries_it(): void
    {
        $render = $this->renderer();
        $solution = FeeFixedPoint::under($this->parameters())->solve($render);

        $bytes = $render($solution->fee);

        $this->assertSame(strlen($bytes), $solution->transactionBytes);
        $this->assertSame(
            FeeCalculator::under($this->parameters())->forBytes($bytes)->value,
            $solution->fee->value,
            'The settled fee has to be exactly the minimum for the transaction that ends up carrying it.'
        );
    }

    /**
     * The whole point. Starting from nought, the fee the first pass computes is written into a wider field than the
     * nought it replaced, so the transaction grows and the first answer is too small for it.
     */
    public function test_a_single_pass_fee_does_not_cover_the_transaction_it_is_written_into(): void
    {
        $render = $this->renderer();
        $calculator = FeeCalculator::under($this->parameters());

        $firstPass = $calculator->forBytes($render(Natural::zero()));
        $withThatFee = $render($firstPass);

        $this->assertTrue(
            $calculator->forBytes($withThatFee)->isGreaterThan($firstPass),
            'Writing the first pass fee in should grow the transaction past what that fee covers.'
        );
    }

    public function test_it_takes_more_than_one_pass_and_reports_how_many(): void
    {
        $solution = FeeFixedPoint::under($this->parameters())->solve($this->renderer());

        $this->assertGreaterThan(1, $solution->passes, 'A run that settles in one pass never exercised the loop.');
        $this->assertFalse($solution->padded);
    }

    /**
     * The same transaction at every plausible shape still settles, and settles on a fee that covers it. This is the
     * property, not the particular number: a fee that covers its own transaction, whatever the transaction is.
     */
    public function test_it_settles_for_every_shape_tried(): void
    {
        $fixedPoint = FeeFixedPoint::under($this->parameters());
        $calculator = FeeCalculator::under($this->parameters());

        foreach ([1, 2, 5, 20, 60] as $outputs) {
            foreach ([1, 2, 7] as $signatures) {
                $render = $this->renderer($outputs, $signatures);
                $solution = $fixedPoint->solve($render);

                $this->assertSame(
                    $calculator->forBytes($render($solution->fee))->value,
                    $solution->fee->value,
                    sprintf('%d outputs and %d signatures did not settle.', $outputs, $signatures)
                );
            }
        }
    }

    /**
     * Padding the fee field to full width removes the loop, at the cost of the bytes it pads with.
     */
    public function test_a_padded_fee_field_settles_in_one_pass_and_costs_more(): void
    {
        $fixedPoint = FeeFixedPoint::under($this->parameters());

        $narrow = $fixedPoint->solve($this->renderer());
        $padded = $fixedPoint->padded($this->renderer(
            feeField: static fn (Natural $fee): CborInteger => CborInteger::widest($fee->value)
        ));

        $this->assertSame(1, $padded->passes);
        $this->assertTrue($padded->padded);
        $this->assertTrue(
            $padded->fee->isGreaterThan($narrow->fee),
            'Padding buys the loop away with bytes, so it has to cost more than iterating.'
        );
        $this->assertSame(
            $narrow->transactionBytes + 4,
            $padded->transactionBytes,
            'A five byte fee field padded to nine is four bytes.'
        );
    }

    /**
     * Padding only works if the field really is fixed width. A caller that asks for the padded path and then writes
     * a narrow field is told, rather than handed a fee for a transaction that is not the one it will submit.
     */
    public function test_padding_refuses_a_field_that_is_not_actually_padded(): void
    {
        $this->expectException(ArithmeticException::class);
        $this->expectExceptionMessage('not written at full width');

        FeeFixedPoint::under($this->parameters())->padded($this->renderer());
    }

    /**
     * A transaction whose size does not depend on its fee settles in two passes and is not an error. Stated because
     * it looks like one: the callback ignores its argument, and the loop is fine with that.
     */
    public function test_a_transaction_whose_size_does_not_move_settles_immediately(): void
    {
        $solution = FeeFixedPoint::under($this->parameters())->solve(
            static fn (Natural $fee): string => str_repeat('x', 400)
        );

        $this->assertSame((string) (155381 + 44 * 400), $solution->fee->value);
        $this->assertSame(2, $solution->passes);
    }

    /**
     * Writing a larger fee cannot make a transaction smaller. A callback that says otherwise is measuring something
     * other than the transaction the fee went into, and the loop stops rather than settling on whichever number the
     * oscillation happened to land on.
     */
    public function test_it_refuses_a_renderer_whose_transaction_shrinks_as_the_fee_rises(): void
    {
        $this->expectException(ArithmeticException::class);
        $this->expectExceptionMessage('not the one the fee was written into');

        FeeFixedPoint::under($this->parameters())->solve(
            static fn (Natural $fee): string => str_repeat(
                'x',
                max(200, 4000 - intdiv($fee->toInt(), 1000))
            )
        );
    }

    /**
     * A transaction that grows faster than its fee can cover never settles. The loop reports that rather than
     * running forever.
     */
    public function test_it_gives_up_on_a_transaction_that_never_settles(): void
    {
        $this->expectException(ArithmeticException::class);
        $this->expectExceptionMessage('did not settle');

        FeeFixedPoint::under($this->parameters())->solve(
            static fn (Natural $fee): string => str_repeat('x', 400 + intdiv($fee->toInt(), 20))
        );
    }

    /**
     * The encoding boundary the loop exists for, stated on its own. Nothing else in the suite would show that the
     * width changes at exactly these numbers.
     */
    public function test_the_fee_field_widens_at_the_cbor_boundaries(): void
    {
        $this->assertSame(1, FeeFixedPoint::feeFieldBytes(Natural::of(23)));
        $this->assertSame(2, FeeFixedPoint::feeFieldBytes(Natural::of(24)));
        $this->assertSame(2, FeeFixedPoint::feeFieldBytes(Natural::of(255)));
        $this->assertSame(3, FeeFixedPoint::feeFieldBytes(Natural::of(256)));
        $this->assertSame(3, FeeFixedPoint::feeFieldBytes(Natural::of(65535)));
        $this->assertSame(5, FeeFixedPoint::feeFieldBytes(Natural::of(65536)));
        $this->assertSame(5, FeeFixedPoint::feeFieldBytes(Natural::of(4294967295)));
        $this->assertSame(9, FeeFixedPoint::feeFieldBytes(Natural::of('4294967296')));
    }
}
