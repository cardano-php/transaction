# cardano-php/transaction

Reading, building and costing Cardano transactions in PHP. Transaction serialization, addresses, native scripts,
slot arithmetic, fees, minimum UTxO and coin selection.

The package holds no keys and signs nothing. It verifies a signature that already exists, because reading a witness
back out of a transaction means checking one, and it goes no further than that. Key custody belongs to whatever is
using this, and `TransactionScopeTest` fails if a call that would make, hold or use a private key appears here.

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
