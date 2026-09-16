# Native scripts at the depths that matter

Eight generated scripts, recorded with the depth each nests to, the bytes it weighs and the hash it takes.

## Why these are generated and the others are not

Every other fixture here was fetched. These could not be. The deepest vector in the conformance corpus under
`../arachne` nests 64 levels, and the deepest of the ten mainnet scripts under `../cardano-scripts` is shallower
still. Neither is anywhere near what the chain will carry: a node accepted a script nested 5,383 levels deep on
preprod, in transaction `f90dce5765108da976abdbb9fc618f9a6ffd9fa4d93b2f288eed1808545424c9`, and refused 5,384 for
size.

Nobody writes a script like that on purpose. A script the ledger accepts has to be readable here, and the only ones
deep enough to prove it are ones built for the purpose.

## What the generator does, and what it does not

`generate.php` writes `depths.json`. Every case is built twice and the two have to agree:

- `DeepScripts::script()` puts the script together out of the package's public builders;
- `DeepScripts::bytes()` writes the same script out as a string of repeated CBOR with no model involved at all.

The sizes are therefore arithmetic rather than a report of what the encoder happened to produce, and the arithmetic
is below. The hashes are this package's own output. They pin the encoder against its past self at depths nothing
recorded reaches; they are not an outside answer, and the arachne corpus is what supplies one of those.

## The key hash

One key hash appears in every case that ends in a `sig`. It is the blake2b-224 of the ASCII bytes of
`cardano-php/transaction/deep-scripts`, which is `60ad9cc7c9e10ee82812928dba9b67dcdd011ddcea9b6425cfc15f03`. It holds
no key material, because nobody knows a key whose hash it is.

## The arithmetic

Three shapes, and what one level of each costs.

| Shape                 | A level                    | Bytes | The leaf                          | Bytes |
| --------------------- | -------------------------- | ----- | --------------------------------- | ----- |
| `all-around-sig`      | `82 01 81`                 | 3     | `82 00 58 1c` and 28 more         | 32    |
| `all-to-empty`        | `82 01 81`                 | 3     | `82 01 80`, an `all` of nothing   | 3     |
| `rotating-around-sig` | `82 01 81`, `82 02 81`, or | 3, 3, | `82 00 58 1c` and 28 more         | 32    |
|                       | `83 03 01 81`              | 4     |                                   |       |

`82` opens a two-item array, the byte after it is the constructor, and `81` is a list of one thing. An `atLeast`
carries its threshold as well, so it is a three-item array and costs a fourth byte. Three bytes is the floor, which
is what makes depth the axis a script grows along most slowly.

| Case                         | Depth | Sum                               | Bytes  |
| ---------------------------- | ----- | --------------------------------- | ------ |
| `one-level`                  | 1     | 3 + 32                            | 35     |
| `past-the-corpus`            | 65    | 65 x 3 + 32                       | 227    |
| `rotating-past-the-corpus`   | 65    | 22 x 3 + 22 x 3 + 21 x 4 + 32     | 248    |
| `node-accepted`              | 5,383 | 5,383 x 3 + 32                    | 16,181 |
| `rotating-at-the-node-depth` | 5,383 | 1,795 x 3 + 1,794 x 3 + 1,794 x 4 |        |
|                              |       | + 32                              | 17,975 |
| `largest-sig-terminated`     | 5,450 | 5,450 x 3 + 32                    | 16,382 |
| `at-the-limit`               | 5,461 | 5,460 x 3 + 3                     | 16,383 |
| `past-the-limit`             | 5,462 | 5,461 x 3 + 3                     | 16,386 |

The 16,181 of `node-accepted` is the size the node measured the accepted script at, which is the one number in this
file that came from somewhere else.

## Why the depths are these depths

`maxTxSize` is 16,384 bytes, and the epoch parameters under `../cardano-ledger` record it. A native script reaches
the chain only inside a transaction, by a witness set, by an output holding it as a reference script, or in the
auxiliary data, and all three are weighed against that limit. A reference script is no exception: the transaction
that creates it carries the whole script in one of its outputs, so that route buys many scripts in one transaction
rather than one larger script.

So `at-the-limit` at 16,383 bytes is the deepest script of any shape that could be inside a transaction, and it is
where this package stops. `largest-sig-terminated` is the deepest one that ends in a signature, and `node-accepted`
is lower than both because a real spending transaction also pays for its input, its fee and a witness.
`past-the-limit` is two bytes larger than a transaction holds, and is the case the refusal is measured against.

The seventy-eight levels between `node-accepted` and `at-the-limit` are the whole of the margin. Reading further
would mean accepting scripts no transaction could deliver, and handing back a tree of objects that PHP frees by
recursing into it. The stack a process is given runs out somewhere near six thousand levels on a megabyte, without
an exception and without a message.

Every number here follows from `maxTxSize`, which is a protocol parameter. Governance can move it, and if it moves
these depths move with it.

## Regenerating

```
php tests/fixtures/deep-scripts/generate.php
```

A hash that changes is the encoder changing, and that is a script hash, which is an address. Nothing here should
move without a reason that is written down.
