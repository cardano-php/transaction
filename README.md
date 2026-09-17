# cardano-php/transaction

Reading, building, costing and signing Cardano transactions in PHP. Transaction serialization, addresses, native
scripts, slot arithmetic, fees, minimum UTxO, coin selection and witness assembly.

The package takes custody of no key. It signs with a key it is handed, for the length of one call, and it does not
encrypt one, derive one from a passphrase or write one down. Storing a key belongs to whatever is using this, and
`TransactionScopeTest` fails if a call that would keep one appears here.

## Install

```
composer require cardano-php/transaction
```

Requires PHP 8.2 or newer with the bcmath and sodium extensions.

## What is in it

| Namespace | What it does |
| --- | --- |
| `Cardano\Transaction\Codec` | Bytes in, a `Transaction` out, or a `DecodeException` naming what was wrong. |
| `Cardano\Transaction\Primitives` | The transaction model: body, inputs, outputs, values, multi-assets, witnesses, auxiliary data. |
| `Cardano\Transaction\Builder` | Assembling a transaction body field by field, in the order the ledger expects it. |
| `Cardano\Transaction\Signing` | An Ed25519 key that lives for one call, and the signer that turns a body hash into a witness set. |
| `Cardano\Transaction\Address` | Base, enterprise, pointer and reward addresses, credentials and networks. |
| `Cardano\Transaction\Script` | Native scripts, their CBOR and their script hashes. |
| `Cardano\Transaction\Ledger` | Protocol parameters, the minimum fee, the fee that is inside the thing it is charged on, minimum UTxO, value size and witness sizing. |
| `Cardano\Transaction\Selection` | Coin selection, change strategies and packing a bag of assets into outputs that each satisfy the rules. |
| `Cardano\Transaction\Time` | Era summaries, and converting a wall-clock instant to a slot. |
| `Cardano\Transaction\Cbor` | The CBOR layer. Integers that remember the width they arrived in, and the forms an array or a map was written in. |
| `Cardano\Transaction\Exception` | Everything thrown from here. |

A transaction re-encodes itself from the model it decoded into, byte for byte, so the hash the model computes is a
statement about the decoder rather than about the bytes it was handed. That is what the corpus under `tests/fixtures`
checks, against transactions taken off mainnet and preprod.

## Building and signing

The order is select, build, measure, settle the fee, sign. The fee is a field of the body and the signatures are taken
over the body, so a signature made before the fee settles covers a body that no longer exists.
`TransactionBodyBuilder` writes the body with a fee of nought and `FeeFixedPoint` settles the fee against the size of
the witnessed transaction; only then is the body signed and the placeholder witnesses replaced.

A witness signs the thirty-two byte blake2b-256 of the body and nothing else. Signatures made over any other message
verify against each other and are refused by every node, so both checks here are against something this package did
not produce. The RFC 8032 vectors pin the Ed25519 primitive, and the committed mainnet corpus pins the message,
because every vkey witness on those transactions verifies against the body hash this package computes.

Everything the builder emits is assembled as CBOR and read back through the decoder before it is handed out, so a
body or witness set built here is one the decoder has already accepted. Bodies are written with fields in ascending
key order, empty fields left out and sets untagged, which is what the rest of the ecosystem emits, so a byte
comparison against another implementation shows a real difference rather than a layout choice.

A `SigningKey` keeps its secret in a closure rather than a property, refuses to be serialized, cloned or dumped, and
zeroes itself when it is discarded or collected. The zeroing is best effort: PHP copies strings on write and does
not track the copies, so discarding guarantees that the object stops holding a key, not that the process does.

## Native scripts

A list of sub-scripts has two valid framings, and a container holding 24 or more of them therefore has two valid
script hashes and two valid addresses. cardano-binary, which cardano-node and cardano-cli serialize through, writes a
definite length up to 23 items and an indefinite length above that; cardano-serialization-lib, MeshJS and most of the
JavaScript ecosystem write a definite length at every size. The ledger accepts both and hashes whichever bytes it was
handed, so neither is canonical.

A script built here takes the framing cardano-binary writes, so the script hash derived from a script file is the
hash `cardano-cli transaction policyid` prints for the same file, at every width. A caller deriving the address a
JavaScript wallet will derive asks for the other by name, with `NativeScript::fromArray($json, Framing::Definite)` or
`$script->framed(Framing::Definite)`. Below 24 sub-scripts the two are byte-identical and the question does not
arise, which is why almost no real script ever meets it.

A script read from bytes keeps the framing each of its containers arrived in, so it re-encodes to the bytes it came
from and keeps the hash the chain published for it. `framings()` says which encoders would have written those bytes.
A witness set goes further and never rebuilds a script at all: it hashes each one exactly as it was written, which is
the path a transaction takes.

A slot is a `uint`, so it runs from 0 to 2^64-1. The upper half of that is past what a PHP integer holds, so a slot
up there is given as a decimal string or a `BigInteger` and carried whole; an ordinary slot is an ordinary integer.
Anything outside the range is refused, because a script carrying one still hashes to a real address and still takes
funds, and only a transaction trying to spend it fails, in the node's decoder rather than at script validation.

The grammar admits degenerate scripts, and this package builds them. A container with no sub-scripts has no condition
left to fail, so `{"type": "all", "scripts": []}` is satisfied by every transaction, including one carrying no
witnesses at all: anyone who finds the address can spend what sits at it. An `atLeast` whose threshold is zero or
negative says the same thing. That script is on mainnet. It hashes to
`d441227553a0f1a965fee7d60a0f724b368dd1bddbc208730fccebcf` and has been there since epoch 392. cardano-cli builds it,
the node accepts it, and asking this package for one gets you one.

An `any` with no sub-scripts is the opposite case. No branch can succeed, so nothing satisfies it and what it guards
cannot be spent by anybody.

The one shape this package refuses is a threshold above the number of sub-scripts beside it, which can never be met.
cardano-cli refuses it too.

Nothing in the grammar bounds how deep a script nests. What bounds it is `maxTxSize`, 16,384 bytes. A script
reaches the chain only inside a transaction: in a witness set, in an output that holds it as a reference script, or
in the auxiliary data, and all three are weighed against that limit. A reference script is no exception, because the
transaction that creates it carries the whole script in one of its outputs. One level of nesting costs three bytes,
`82 01 81`, so the deepest script a transaction could physically carry is 5,461 levels, and the deepest a node has
accepted is 5,383, in preprod transaction
`f90dce5765108da976abdbb9fc618f9a6ffd9fa4d93b2f288eed1808545424c9`, with 5,384 refused for size.

This package reads all 5,461 of them and refuses anything deeper with an exception that names the limit. Every walk
over a script carries its own stack rather than PHP's, because a recursion that deep is a dead process rather than
something to catch. Reading further would mean handing back scripts that PHP itself cannot free, since a tree of
objects is released by recursing into it. The stack a process is given runs out somewhere near six thousand levels
on a megabyte, and past fifty thousand on the eight megabytes a Linux process gets by default.

The JSON form stops far earlier, at 255 levels, and says so rather than writing out a file that parses as nothing.
That ceiling is PHP's, whose JSON reader and writer nest 512 levels by default and whose reader stops a few thousand
levels in whatever allowance it is given. CBOR is what the chain carries and what to read a deep script from.

## The public surface

Everything under `Cardano\Transaction\` is public except the classes whose docblock carries `@internal`. Those are
the package's own reading machinery, they are named by no public signature, and they can change in a patch release.
`PublicSurfaceTest` fails if a public signature ever hands one out.

## Tests

```
composer install
composer test
```

The suite makes no network calls. Every fixture was fetched once, checked against something other than the code it
tests before it was written, and committed with a note saying where it came from. CI runs it on PHP 8.2, 8.3 and
8.4.

Signing is checked from outside twice: against the RFC 8032 Ed25519 vectors under `tests/fixtures/ed25519`, and
against the same transaction built by `@meshsdk/core-cst`, whose output was generated once and committed under
`tests/vectors/differential`, so the suite needs no Node and no network.

The native script encoder is checked twice over: against ten scripts taken off mainnet with the hashes the chain
indexed them under, and against a conformance corpus generated by a separate implementation in another language. The
corpus is 126 scripts, each with both of its CBOR encodings, its script hashes and the addresses those hashes occupy.
It also carries 1067 sampled witness sets with the answer the ledger's evaluator gives for each, and three
submissions to preprod with the answer a node gave. This package reproduces both encodings of every script it can
build. `tests/fixtures/arachne/README.md` records where the corpus came from, which of it this package reproduces,
and the one shape this package refuses to build.

## License and file headers

Apache-2.0. See `LICENSE` and `NOTICE`.

Every PHP file in `src` and `tests` opens with the same two SPDX lines, directly under the opening tag and above
anything else:

```php
<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0
```

A machine reading the tree for license data reads those lines. A file copied out of here carries its license with
it, instead of leaving it behind in a LICENSE file at a path the copy no longer has.
`PackageIsSelfContainedTest` fails on a file that does not open this way.
