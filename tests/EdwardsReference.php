<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

namespace Cardano\Transaction\Tests;

use Brick\Math\BigInteger;
use Cardano\Transaction\Signing\Edwards25519;

/**
 * Edwards point arithmetic from the curve equation, for the tests to check the signing path against.
 *
 * None of this ships. It is slow and variable time, and it exists so that the point the package chooses out of a
 * ristretto255 coset can be compared with one computed another way: k times the base point by double and add, and
 * the prime-order member of a coset as P - [L]P. That second one works because L is 1 modulo 4, so multiplying the
 * decoded point by L sends its prime-order part to the identity and leaves its small-order part where it is.
 */
final class EdwardsReference
{
    private const P = '57896044618658097711785492504343953926634992332820282019728792003956564819949';

    private const L = '7237005577332262213973186563042994240857116359379907606001950938285454250989';

    private const D = '37095705934669439343138083508754565189542113879843219016388785533085940283555';

    /** The affine coordinates of the base point, from RFC 8032, section 5.1. */
    private const BASE_X = '15112221349535400772501151409588531511454012693041857206046113283949847762202';

    private const BASE_Y = '46316835694926478169428394003475163141307993866256225615783033603165251855960';

    /** The Edwards encoding of k times the base point. */
    public static function multiplyBase(int|string $k): string
    {
        $x = BigInteger::of(self::BASE_X);
        $y = BigInteger::of(self::BASE_Y);

        return self::encode(self::multiply([$x, $y, BigInteger::one(), self::mul($x, $y)], BigInteger::of($k)));
    }

    /** The member of a ristretto255 coset in the prime-order subgroup, as P - [L]P. */
    public static function primeOrderPoint(string $ristretto): string
    {
        [$x, $y] = Edwards25519::decodeRistretto($ristretto);
        $point = [$x, $y, BigInteger::one(), self::mul($x, $y)];
        $torsion = self::multiply($point, BigInteger::of(self::L));
        $negated = [self::neg($torsion[0]), $torsion[1], $torsion[2], self::neg($torsion[3])];

        return self::encode(self::add($point, $negated));
    }

    /**
     * @param  array{BigInteger, BigInteger, BigInteger, BigInteger}  $point
     * @return array{BigInteger, BigInteger, BigInteger, BigInteger}
     */
    private static function multiply(array $point, BigInteger $k): array
    {
        $result = [BigInteger::zero(), BigInteger::one(), BigInteger::one(), BigInteger::zero()];
        $bits = $k->toBase(2);

        for ($i = 0, $n = strlen($bits); $i < $n; $i++) {
            $result = self::add($result, $result);

            if ($bits[$i] === '1') {
                $result = self::add($result, $point);
            }
        }

        return $result;
    }

    /**
     * Point addition in extended coordinates, RFC 8032 section 5.1.4. The formula is complete, so it doubles too.
     *
     * @param  array{BigInteger, BigInteger, BigInteger, BigInteger}  $p
     * @param  array{BigInteger, BigInteger, BigInteger, BigInteger}  $q
     * @return array{BigInteger, BigInteger, BigInteger, BigInteger}
     */
    private static function add(array $p, array $q): array
    {
        [$x1, $y1, $z1, $t1] = $p;
        [$x2, $y2, $z2, $t2] = $q;

        $a = self::mul(self::sub($y1, $x1), self::sub($y2, $x2));
        $b = self::mul($y1->plus($x1), $y2->plus($x2));
        $c = self::mul(self::mul($t1->multipliedBy(2), BigInteger::of(self::D)), $t2);
        $d = self::mul($z1->multipliedBy(2), $z2);
        $e = self::sub($b, $a);
        $f = self::sub($d, $c);
        $g = $d->plus($c)->mod(self::P);
        $h = $b->plus($a)->mod(self::P);

        return [self::mul($e, $f), self::mul($g, $h), self::mul($f, $g), self::mul($e, $h)];
    }

    /**
     * @param  array{BigInteger, BigInteger, BigInteger, BigInteger}  $p
     */
    private static function encode(array $p): string
    {
        $zInverse = $p[2]->modPow(BigInteger::of(self::P)->minus(2), self::P);
        $x = self::mul($p[0], $zInverse);
        $y = self::mul($p[1], $zInverse);

        $bytes = strrev((string) hex2bin(str_pad($y->toBase(16), 64, '0', STR_PAD_LEFT)));

        if ($x->isOdd()) {
            $bytes[31] = chr(ord($bytes[31]) | 0x80);
        }

        return $bytes;
    }

    private static function mul(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->multipliedBy($b)->mod(self::P);
    }

    private static function sub(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->minus($b)->mod(self::P);
    }

    private static function neg(BigInteger $a): BigInteger
    {
        return BigInteger::zero()->minus($a)->mod(self::P);
    }
}
