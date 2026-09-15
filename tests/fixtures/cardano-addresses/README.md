# CIP-19 address vectors

The published test vectors from CIP-19, Cardano Addresses, for all ten address types on both networks.

## Where these came from

`cip19-vectors.json` holds the bech32 strings copied verbatim from the Test Vectors section of
https://raw.githubusercontent.com/cardano-foundation/CIPs/master/CIP-0019/README.md, fetched on 15 September 2026.
Beside them are the payment key, stake key, script and pointer the CIP says they were all built from.

Everything beside each address is derived, and none of it is derived by the code under test:

- `bytes`, `header` and `payload` come from decoding the published address with the BIP-173 reference bech32
  implementation, with the length limit removed as Cardano does;
- `payment_key_hash` and `stake_key_hash` come from hashing the published verification keys with Python's hashlib
  blake2b at a digest length of twenty-eight bytes, not with the libsodium binding the tests call;
- `script_hash` comes from decoding the published `script1...` bech32 string.

That matters because a test that built an address with this repository's code and then checked it against an
expectation computed by the same code would pass whatever the code did. The published strings are the only value in
the file that nothing here can influence, and every other field was produced by a second implementation.

## What they pin

The pointer vector is the only place where the variable length natural encoding of CIP-19 is exercised against a
published value. The pointer is `(2498243, 27, 3)`, and it encodes to `8198bd431b03`. Self-consistency could not
have caught that encoding being wrong.

The two stake vectors are the only place where the `stake` and `stake_test` prefixes and the type 14 and 15 headers
are pinned to anything outside this repository.

## Refetching

```
curl -s https://raw.githubusercontent.com/cardano-foundation/CIPs/master/CIP-0019/README.md
```

The Test Vectors section is near the end. If a vector in this file ever disagrees with that document, this file is
wrong.
