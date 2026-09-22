<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Signing;

use Brick\Math\BigInteger;
use Cardano\Transaction\Exception\SigningException;

/**
 * Point arithmetic on edwards25519 for public points only.
 *
 * @internal This is the signing path's own machinery. It is named by no public signature and can change in a patch
 * release.
 *
 * **Nothing secret may pass through this class.** Every operation here runs on arbitrary-precision integers, whose
 * running time depends on the values involved, so it is safe only for data an observer already has. The two callers
 * hand it exactly that: the nonce commitment R of a signature, which is published as the first half of the signature,
 * and a verification key, which is published in every witness. The secret scalars stay inside libsodium, which
 * computes the point and hands back its ristretto255 encoding; all this class does is turn that public encoding into
 * the Edwards encoding a signature and a witness carry.
 *
 * **Why the conversion is needed.** PHP's sodium extension exposes no Ed25519 base point multiplication that takes a
 * scalar as given. Every Ed25519 function it has clamps the scalar first, and an extended BIP32-Ed25519 key, or a
 * signature nonce, has to be multiplied exactly as it is. The ristretto255 base multiplication does not clamp, and a
 * ristretto255 element is a coset of four edwards25519 points that differ by a point of order dividing four. So the
 * point libsodium computed is one of the four points a ristretto255 decode (RFC 9496, section 4.3.1) can reach, and
 * candidates() lists all four.
 */
final class Edwards25519
{
    /** The field prime, 2^255 - 19. */
    private const P = '57896044618658097711785492504343953926634992332820282019728792003956564819949';

    /** The order of the prime-order subgroup, 2^252 + 27742317777372353535851937790883648493. */
    private const L = '7237005577332262213973186563042994240857116359379907606001950938285454250989';

    /** The curve constant d = -121665 / 121666. */
    private const D = '37095705934669439343138083508754565189542113879843219016388785533085940283555';

    /** A square root of -1, 2^((p - 1) / 4). */
    private const SQRT_M1 = '19681161376707505956807079304988542015446066515923890162744021073123829784752';

    /** The affine coordinates of the base point, from RFC 8032, section 5.1. */
    private const BASE_X = '15112221349535400772501151409588531511454012693041857206046113283949847762202';

    private const BASE_Y = '46316835694926478169428394003475163141307993866256225615783033603165251855960';

    /** @var array<string, BigInteger> */
    private static array $constants = [];

    private function __construct() {}

    /**
     * The four Edwards encodings a ristretto255 encoding stands for.
     *
     * Exactly one of them is the point in the prime-order subgroup, which is the one a signature or a key has to
     * carry; the other three differ from it by a point of small order. The order returned is fixed, so the caller's
     * choice between them does not depend on anything but the encoding it passed in.
     *
     * @return list<string> four 32-byte Edwards encodings
     */
    public static function candidates(string $ristretto): array
    {
        [$x, $y] = self::decodeRistretto($ristretto);
        $i = self::constant('SQRT_M1');

        return [
            self::encode($x, $y),
            self::encode(self::neg($x), self::neg($y)),
            self::encode(self::mul($i, $y), self::mul($i, $x)),
            self::encode(self::neg(self::mul($i, $y)), self::neg(self::mul($i, $x))),
        ];
    }

    /**
     * The one Edwards encoding of a ristretto255 element that lies in the prime-order subgroup.
     *
     * A point P decoded from ristretto255 is Q + T, with Q in the prime-order subgroup and T of order one, two or
     * four. L is 1 modulo 4, so multiplying by L sends Q to the identity and leaves T where it is: [L]P = T, and
     * P - [L]P = Q. One scalar multiplication settles it, rather than one per candidate.
     */
    public static function primeOrderPoint(string $ristretto): string
    {
        [$x, $y] = self::decodeRistretto($ristretto);
        $point = [$x, $y, BigInteger::one(), self::mul($x, $y)];
        $torsion = self::multiply($point, self::constant('L'));

        return self::encodeExtended(self::add($point, self::negate($torsion)));
    }

    /**
     * The Edwards encoding of k times the base point, by double and add.
     *
     * This exists for the tests, which compare the RFC 9496 multiples of the generator against a point computed with
     * nothing but the curve equation. It is variable time, and k must never be a secret.
     */
    public static function multiplyBase(int $k): string
    {
        $base = [self::constant('BASE_X'), self::constant('BASE_Y'), BigInteger::one(), BigInteger::zero()];
        $base[3] = self::mul($base[0], $base[1]);

        return self::encodeExtended(self::multiply($base, BigInteger::of($k)));
    }

    /**
     * Decode a ristretto255 element to affine Edwards coordinates, as RFC 9496 section 4.3.1 specifies.
     *
     * Every refusal the RFC requires is made: a value at or above p, a negative (odd) value, a ratio with no square
     * root, a negative x times y, and y of zero. Libsodium never produces any of those, so reaching one here means
     * the encoding did not come from libsodium, and signing stops rather than guessing.
     *
     * @return array{BigInteger, BigInteger}
     */
    public static function decodeRistretto(string $encoding): array
    {
        if (strlen($encoding) !== 32) {
            throw new SigningException(sprintf('A ristretto255 encoding is 32 bytes, got %d.', strlen($encoding)));
        }

        $s = self::fromLittleEndian($encoding);

        if ($s->isGreaterThanOrEqualTo(self::constant('P'))) {
            throw new SigningException('A ristretto255 encoding must be a canonical field element.');
        }

        if ($s->isOdd()) {
            throw new SigningException('A ristretto255 encoding must be a non-negative field element.');
        }

        $ss = self::mul($s, $s);
        $u1 = self::sub(BigInteger::one(), $ss);
        $u2 = self::reduce(BigInteger::one()->plus($ss));
        $u2Squared = self::mul($u2, $u2);

        $v = self::sub(self::neg(self::mul(self::constant('D'), self::mul($u1, $u1))), $u2Squared);

        [$wasSquare, $invsqrt] = self::sqrtRatioM1(BigInteger::one(), self::mul($v, $u2Squared));

        $denX = self::mul($invsqrt, $u2);
        $denY = self::mul(self::mul($invsqrt, $denX), $v);

        $x = self::abs(self::mul(self::mul(BigInteger::of(2), $s), $denX));
        $y = self::mul($u1, $denY);
        $t = self::mul($x, $y);

        if (! $wasSquare || $t->isOdd() || $y->isZero()) {
            throw new SigningException('This ristretto255 encoding does not decode to a group element.');
        }

        return [$x, $y];
    }

    /**
     * SQRT_RATIO_M1 from RFC 9496 section 4.2.
     *
     * @return array{bool, BigInteger}
     */
    private static function sqrtRatioM1(BigInteger $u, BigInteger $v): array
    {
        $sqrtM1 = self::constant('SQRT_M1');
        $v3 = self::mul(self::mul($v, $v), $v);
        $v7 = self::mul(self::mul($v3, $v3), $v);

        $exponent = self::constant('P')->minus(5)->quotient(8);
        $r = self::mul(self::mul($u, $v3), self::mul($u, $v7)->modPow($exponent, self::constant('P')));
        $check = self::mul($v, self::mul($r, $r));

        $correct = $check->isEqualTo($u);
        $flipped = $check->isEqualTo(self::neg($u));
        $flippedI = $check->isEqualTo(self::neg(self::mul($u, $sqrtM1)));

        if ($flipped || $flippedI) {
            $r = self::mul($r, $sqrtM1);
        }

        return [$correct || $flipped, self::abs($r)];
    }

    /**
     * [k]P in extended coordinates, most significant bit first.
     *
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
        $b = self::mul(self::reduce($y1->plus($x1)), self::reduce($y2->plus($x2)));
        $c = self::mul(self::mul(self::mul($t1, BigInteger::of(2)), self::constant('D')), $t2);
        $d = self::mul(self::mul($z1, BigInteger::of(2)), $z2);
        $e = self::sub($b, $a);
        $f = self::sub($d, $c);
        $g = self::reduce($d->plus($c));
        $h = self::reduce($b->plus($a));

        return [self::mul($e, $f), self::mul($g, $h), self::mul($f, $g), self::mul($e, $h)];
    }

    /**
     * @param  array{BigInteger, BigInteger, BigInteger, BigInteger}  $p
     * @return array{BigInteger, BigInteger, BigInteger, BigInteger}
     */
    private static function negate(array $p): array
    {
        return [self::neg($p[0]), $p[1], $p[2], self::neg($p[3])];
    }

    /**
     * @param  array{BigInteger, BigInteger, BigInteger, BigInteger}  $p
     */
    private static function encodeExtended(array $p): string
    {
        $zInverse = $p[2]->modPow(self::constant('P')->minus(2), self::constant('P'));

        return self::encode(self::mul($p[0], $zInverse), self::mul($p[1], $zInverse));
    }

    /**
     * The RFC 8032 encoding of an affine point: y in little-endian, with the low bit of x in the top bit.
     */
    private static function encode(BigInteger $x, BigInteger $y): string
    {
        $bytes = strrev((string) hex2bin(str_pad($y->toBase(16), 64, '0', STR_PAD_LEFT)));

        if ($x->isOdd()) {
            $bytes[31] = chr(ord($bytes[31]) | 0x80);
        }

        return $bytes;
    }

    private static function constant(string $name): BigInteger
    {
        return self::$constants[$name] ??= BigInteger::of(constant(self::class.'::'.$name));
    }

    private static function fromLittleEndian(string $bytes): BigInteger
    {
        return BigInteger::fromBase(bin2hex(strrev($bytes)), 16);
    }

    private static function reduce(BigInteger $a): BigInteger
    {
        return $a->mod(self::constant('P'));
    }

    private static function mul(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->multipliedBy($b)->mod(self::constant('P'));
    }

    private static function sub(BigInteger $a, BigInteger $b): BigInteger
    {
        return $a->minus($b)->mod(self::constant('P'));
    }

    private static function neg(BigInteger $a): BigInteger
    {
        return BigInteger::zero()->minus($a)->mod(self::constant('P'));
    }

    private static function abs(BigInteger $a): BigInteger
    {
        return $a->isOdd() ? self::neg($a) : $a;
    }
}
