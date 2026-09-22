#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Adam Dean
# SPDX-License-Identifier: Apache-2.0
#
# Writes vectors.json beside this file: extended BIP32-Ed25519 signatures computed from the curve equation with
# Python integers and hashlib, sharing no code with the package. See README.md.

import hashlib
import json
import os

P = 2**255 - 19
L = 2**252 + 27742317777372353535851937790883648493
D = -121665 * pow(121666, P - 2, P) % P

# The base point, RFC 8032 section 5.1.
BY = 4 * pow(5, P - 2, P) % P


def recover_x(y, sign):
    xx = (y * y - 1) * pow(D * y * y + 1, P - 2, P) % P
    x = pow(xx, (P + 3) // 8, P)
    if (x * x - xx) % P != 0:
        x = x * pow(2, (P - 1) // 4, P) % P
    if x % 2 != sign:
        x = P - x
    return x


B = (recover_x(BY, 0), BY, 1, recover_x(BY, 0) * BY % P)


def add(p, q):
    # RFC 8032 section 5.1.4, extended coordinates.
    a = (p[1] - p[0]) * (q[1] - q[0]) % P
    b = (p[1] + p[0]) * (q[1] + q[0]) % P
    c = 2 * p[3] * q[3] * D % P
    d = 2 * p[2] * q[2] % P
    e, f, g, h = b - a, d - c, d + c, b + a
    return (e * f % P, g * h % P, f * g % P, e * h % P)


def multiply(k, point):
    result = (0, 1, 1, 0)
    while k > 0:
        if k & 1:
            result = add(result, point)
        point = add(point, point)
        k >>= 1
    return result


def encode(point):
    z_inverse = pow(point[2], P - 2, P)
    x, y = point[0] * z_inverse % P, point[1] * z_inverse % P
    return (y | ((x & 1) << 255)).to_bytes(32, 'little')


def sha512_int(data):
    return int.from_bytes(hashlib.sha512(data).digest(), 'little')


def sign(k_l, k_r, message):
    # The extended signing of Khovratovich and Law, as cardano-crypto implements it. kL is used whole.
    scalar = int.from_bytes(k_l, 'little')
    public = encode(multiply(scalar, B))
    r = sha512_int(k_r + message) % L
    commitment = encode(multiply(r, B))
    k = sha512_int(commitment + public + message) % L
    return public, commitment + ((r + k * scalar) % L).to_bytes(32, 'little')


def derived(label, i, size):
    return hashlib.sha512(f'cardano-php/transaction ed25519-bip32 {label} {i}'.encode()).digest()[:size]


def k_l_for(shape, i):
    raw = bytearray(derived('kL ' + shape, i, 32))
    raw[0] &= 0xF8  # every BIP32-Ed25519 kL is a multiple of eight
    if shape == 'top bit set':
        raw[31] |= 0x80  # kL at or above 2^255
    elif shape == 'bit 254 clear':
        raw[31] &= 0x3F  # below 2^254, which a clamped scalar never is
    elif shape == 'root clamped':
        raw[31] = (raw[31] & 0x1F) | 0x40  # the shape a BIP32-Ed25519 root key has
    elif shape == 'below L':
        raw[31] &= 0x0F  # smaller than the group order, so reduction changes nothing
    return bytes(raw)


SHAPES = ['top bit set', 'bit 254 clear', 'root clamped', 'below L', 'any multiple of eight']
MESSAGES = [b'', b'\x72', bytes(32), hashlib.sha256(b'cardano-php/transaction').digest(), bytes(range(256))]

vectors = []
for shape in SHAPES:
    for i in range(3):
        k_l = k_l_for(shape, i)
        k_r = derived('kR ' + shape, i, 32)
        message = MESSAGES[(len(vectors)) % len(MESSAGES)]
        public, signature = sign(k_l, k_r, message)
        vectors.append({
            'shape': shape,
            'kL': k_l.hex(),
            'kR': k_r.hex(),
            'public_key': public.hex(),
            'message': message.hex(),
            'signature': signature.hex(),
        })

document = {
    'source': {
        'generated_by': 'generate.py',
        'note': 'Computed by generate.py from the curve equation with Python integers and hashlib, sharing no code with this package. The keys are test values derived from fixed labels and have never held funds.',
    },
    'vectors': vectors,
}

with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'vectors.json'), 'w') as out:
    json.dump(document, out, indent=4)
    out.write('\n')
