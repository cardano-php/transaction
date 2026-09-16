# An independent conformance corpus for native scripts

126 generated native scripts, each recorded with both of its CBOR encodings, both script hashes, the addresses those
hashes occupy on mainnet and the testnets, and the answer the ledger's evaluator gives for a sample of witness sets.

## Why a corpus from somewhere else

The other fixtures under `tests/fixtures` check this code against the chain, by holding a provider's reply beside the
input it was computed from. This one checks it against a second implementation. Every value here was computed by that
implementation, in another language, from a written specification, and none of it was computed by the code it is used
to check.

The ten recorded mainnet scripts under `../cardano-scripts` prove the encoder at ten points the chain has already
agreed with. These prove it at 119 more, on edges the chain's own scripts do not reach: containers of 400
sub-scripts, nesting 65 levels deep, thresholds one either side of every boundary, and the same key hash named twice
under one threshold.

## Where these came from

```
https://github.com/Crypto2099/arachne
commit 039fd334752f44663982c9c98b852ed4c1d99178
path   vectors/
copied 15 September 2026
```

The files under `vectors/` are that directory, byte for byte, with nothing edited on the way in. The corpus carries a
digest over every vector's `id` and both of its script hashes, and `ArachneCorpusTest` recomputes it from the files on
disk and compares it against the one the index records.

```
format version 2
generator      arachne@0.1.0
digest         a6688f76c41e96e5bc1ebd45ad0bd148898d0bc8f3b5fdcca4c4059f
vectors        126 in 11 families
witness sets   1067
observations   0
```

The 980 key hashes in the corpus hold no key material. Each is the blake2b-224 of the UTF-8 bytes of
`arachne/cosigner/` followed by a short label, so anyone can rebuild every one of them with no wallet and no seed, and
nothing can sign for any of them. `ArachneCorpusTest` rebuilds all 980 from their labels.

The `onchain` array of every vector is empty. Encoding and satisfaction are settled offline and completely; whether a
node accepts a transaction carrying one of these scripts is a separate question that only submission answers, and
neither the corpus nor this suite answers it.

## What the suite checks

For each of the 119 vectors this package can build:

- the CBOR, as a byte comparison, and the preimage the hash is taken over;
- the script hash, and the policy identifier and the credential that carry the same bytes;
- the enterprise address, the base address with the script in both slots, and the reward address, on mainnet, preview
  and preprod;
- the script read back from its own bytes, down to the structure;
- every witness set the corpus sampled for it, 1012 in all.

## The degenerate shapes

Seventeen vectors hold a container with no sub-scripts, or a threshold outside the range its sub-script count allows.
The grammar admits every one of them. `script_all = (1, [* native_script])` is zero or more sub-scripts, and `n` in
`script_n_of_k = (3, n : int64, [* native_script])` is a signed 64-bit integer, so an empty container and a threshold
at or below zero both encode and both hash to a real script hash at a real address.

cardano-cli builds ten of the seventeen and refuses the other seven:

| Shape                             | Vectors | cardano-cli                                                                      |
| --------------------------------- | ------- | -------------------------------------------------------------------------------- |
| A container with no sub-scripts   | 3       | builds it and prints the hash the corpus records                                 |
| A threshold at or below zero      | 7       | builds it and prints the hash the corpus records                                 |
| A threshold above the child count | 7       | refuses it: "Required number of script signatures exceeds the number of scripts" |

This package builds the same ten and refuses the same seven, as JSON and as CBOR, and `ArachneConformanceTest`
asserts each case. A shape that starts or stops building fails a test rather than quietly changing what the suite
covers. The 55 witness sets the seven refused vectors carry are counted in the suite and named as unreachable.

Two of the ten are on chain. `{"type": "all", "scripts": []}` hashes to
`d441227553a0f1a965fee7d60a0f724b368dd1bddbc208730fccebcf` and has been on mainnet since epoch 392, created by
transaction `c6ae228099eabfebfadd325f8536e4b63ace258e3c1e1e666b89dd80a3573a4e`.
`{"type": "all", "scripts": [{"type": "all", "scripts": []}]}` hashes to
`60be8259acde0a72b76f36977223cac39432713a42bcfdf76a55dd7f` on preprod, created by transaction
`976cf22d10afac84fc64895eb31c2d3142d1cfef4c7f6b1c2ff72c4ed8cafe61`. Both were read from Koios on 15 September 2026,
at `eu-api.koios.rest` for mainnet and `preprod.koios.rest` for preprod.

An empty `all` is satisfied by a transaction carrying no witnesses at all, so anyone can spend what it guards. The
corpus records that answer for it and the suite asserts it.

## The two encodings of a wide container

A container holding 24 or more sub-scripts has two valid encodings and therefore two valid script hashes. The ledger's
own encoder, which cardano-node and cardano-cli serialize through, writes a definite-length array up to 23 elements
and an indefinite-length array from 24. cardano-serialization-lib and MeshJS write a definite-length array at every
size. Both are well-formed, the ledger accepts both, and the ledger hashes whichever bytes it was given. 15 of the 126
vectors hold a container that wide.

This package writes what the ledger's encoder writes, so the CBOR, hash and address assertions run against the
`cardanoBinary` half of every vector. The `definite` half of those 15 is checked in the two places it can still arrive.
`NativeScript::fromCbor` refuses it rather than re-encoding it, because re-encoding would move its hash. A witness set
carrying those bytes hashes them to the `definite` script hash the corpus records, because a witness set hashes the
bytes each script arrived in and never rebuilds them. Both are asserted for all 15.

The corpus records both hashes for every vector. cardano-cli 10.7.0.0 was run over a sample of them, and agreed with
the corpus in every case:

| Vector                       | Container width | cardano-cli `policyid` | Matches         |
| ---------------------------- | --------------- | ---------------------- | --------------- |
| `encoding-boundary/root-w23` | 23              | `764244af39b93c24...`  | both encodings  |
| `encoding-boundary/root-w24` | 24              | `36db16a605b3ad5e...`  | cardano-binary  |
| `breadth/all-w025`           | 25              | `f6e388cbaae54a63...`  | cardano-binary  |
| `breadth/all-w400`           | 400             | `c604f11a3c347c4b...`  | cardano-binary  |
| `federation/m20-c20-s11`     | 20              | `400a818dace7a947...`  | both encodings  |

This package prints the same hash as cardano-cli in every row, and `ArachneConformanceTest` asserts all five by
their printed values.

## What the suite does not check

Every vector records a DRep, a constitutional committee cold and a constitutional committee hot identifier, in both
the CIP-129 and the CIP-105 form. This package has no governance credential type, so nothing reads them.

## Refreshing the copy

Copy `vectors/` from a named commit again, replace this directory's copy of it, and update the commit and the digest
above. The suite fails on a format version it does not recognize, on a digest that does not match the files, and on a
vector that starts or stops building, so a refresh that changes what is covered says so on the next run.
