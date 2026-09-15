# The ledger as the oracle

Fee, minimum UTxO and value size are three rules the ledger already enforces on every transaction it accepts. That
makes the chain a source of answers this arithmetic can be checked against, rather than against a second copy of
itself written by the same person on the same afternoon.

`mainnet-outputs.jsonl` is 3773 mainnet transactions and the 9830 outputs inside them. `mainnet-epoch-params.json`
is the parameter set those transactions were accepted under.

## What the assertions are

Three, one per rule, and all three are inequalities in the same direction.

1. **Minimum UTxO.** For every one of the 9830 outputs, `(160 + size of the serialized output) * utxoCostPerByte` is
   at or below the coin that output actually holds. The ledger refuses an output that fails this, so every output
   here passed it when it was written, and a single violation means the formula is wrong.
2. **Fee.** For every one of the 3773 transactions, `txFeeFixed + txFeePerByte * size` is at or below the fee that
   transaction actually paid, with size taken over the whole transaction, witnesses included. 175 of them paid it
   to the lovelace.
3. **Value size.** Every output's serialized value is at or below `maxValueSize`.

Each is a one-sided bound, and that is not a weakness of the test, it is the shape of the rule. The ledger sets a
floor on the fee and on the coin in an output, and what was actually paid sits at or above it; a transaction may
pay more, and plenty do. A computed figure above what the chain accepted is unambiguously a bug. A computed figure
below it, across ten thousand real cases with no exception, is the formula agreeing with the ledger everywhere the
chain has been.

## What these fixtures do not settle

- **They cannot catch a formula that comes out too low.** A minimum UTxO computed at one lovelace would pass all
  9830 rows. What rules that out is the other direction: the same formula is used to build outputs, and
  `LedgerArithmeticTest` pins the exact figures for known outputs, while `MinimumUtxoTest` checks that an output
  funded to the computed minimum and then re-measured needs no more.
- **The transactions are Conway and recent.** Every one was accepted under epoch 655's parameters. A fixture set
  spanning a parameter change would need the parameters of each transaction's own epoch, not one set for all of
  them. The five numbers read here have not moved since the Babbage hard fork, which is why one set covers the
  whole corpus, and why extending the corpus backwards past Babbage would need more than a bigger file.
- **Reference scripts are not covered.** Conway charges for them on a rising tier, and a transaction that uses them
  pays more than `txFeeFixed + txFeePerByte * size`. That leaves assertion 2 true and unhelpful for that tier: the
  bound still holds, and says nothing about whether the tier arithmetic is right. Whether an input carried a
  reference script cannot be read from the transaction's own bytes, only from the outputs its inputs point at,
  which this corpus does not hold. The tier is tested against worked figures in `FeeCalculatorTest` instead, and
  that is a weaker thing than a chain oracle.

## What the corpus turned out to contain

Read before assuming a bound is theoretical.

| Fact                                          | Value                                                       |
| --------------------------------------------- | ----------------------------------------------------------- |
| Transactions                                   | 3773                                                         |
| Outputs                                        | 9830                                                         |
| Outputs carrying assets                        | 4569                                                         |
| Largest serialized output                      | 6364 bytes                                                   |
| Largest serialized value                       | 5000 bytes, which is `maxValueSize` exactly                  |
| Most assets in one output                      | 287                                                          |
| Largest single asset quantity                  | 9223372036847645859                                          |

The last two rows are the ones worth stopping on.

A real output holds a value of exactly 5000 bytes. The rule is `<=`, not `<`, and a check written the other way
would refuse a transaction mainnet accepted. Nothing here would have found that by reasoning about it.

The largest asset quantity in the corpus is 9223372036847645859. `PHP_INT_MAX` is 9223372036854775807. Real mainnet
traffic came within nine million of the point where a PHP integer stops working, on a bound that is 2^64 rather
than 2^63, which is to say the boundary is not theoretical and the corpus does not happen to contain a case past
it. That is why every quantity in this layer is a decimal string over ext-bcmath rather than an int, and why
`Natural::toInt()` refuses instead of truncating. The case past the boundary is constructed rather than fetched,
in `NaturalTest` and `LedgerBoundaryTest`, because a fixture set that does not contain one cannot prove the guard.

## The two sizes, which are not the same number

`bytes` and `size` differ by exactly one, for all 3773 rows, and the difference has consequences.

`bytes` is the length of the CBOR the provider returns. `size` is what the chain recorded for the transaction, and
is the number the fee was charged on. Computing `txFeeFixed + txFeePerByte * bytes` says 175 of these transactions
paid less than the protocol minimum, which cannot be true of a transaction a node accepted. Computing it on `size`
says none did, and that 175 paid it to the lovelace.

The byte is the header of the four-item array wrapping body, witnesses, validity flag and auxiliary data. What rules
out the alternative, the one-byte `null` in the auxiliary data slot, is that transactions carrying 261 to 280 bytes
of real auxiliary data show the same difference of one. The difference is constant across every shape in the corpus:
with and without metadata, with and without set tags on the inputs, from 266 bytes to twenty-one thousand.

This build does not rely on it. The builder measures its own serialization and charges on that, which is at most one
byte over what the ledger asks and never under, at a cost of one `txFeePerByte`. Depending on a one-byte relationship
measured through one provider, in one direction, to save forty-four lovelace, is a bad trade. The relationship is
asserted in the suite anyway, so that if it changes, that is a failure rather than a surprise later.

## Format

One JSON object per line:

```json
{ "tx": "<64 hex>", "bytes": 1234, "size": 1233, "fee": "180000", "outputs": ["<hex>", "<hex>"] }
```

`fee` is the body's fee field as a decimal string, never as a number, because a JSON number in PHP is a float and a
lovelace figure passes what a float holds exactly. It was checked against the fee the chain recorded separately for
every row, and matches on all 3773. Each entry in `outputs` is the exact serialized bytes of one output, in the
order the body writes them.

## Where these came from, and how to check them

Koios, mainnet, on 15 September 2026. `eu-api.koios.rest` is a mainnet instance of the service documented at
`api.koios.rest`, and is the host that was reachable from the machine the corpus was fetched on.

The pool is every transaction in 500 consecutive recent blocks, taken through `GET /blocks`, then
`POST /block_txs`, then `POST /tx_cbor`. Not selected by shape: the point of this corpus is the population, not a
list of interesting cases, and picking the interesting ones is what would let a formula that is wrong on the
ordinary ones survive.

Refetching any transaction:

```
curl -s -X POST https://eu-api.koios.rest/api/v1/tx_cbor \
     -H 'Content-Type: application/json' \
     -d '{"_tx_hashes":["<the tx field>"]}'
```

The `cbor` field of the reply decodes, under `TransactionDecoder`, to a transaction whose outputs re-encode to the
hex committed here, and whose body hashes to the `tx` field. All 3773 were checked that way when the file was
written: every one decodes, round trips byte for byte, and hashes to the value it was fetched by. None was dropped
for failing any of the three.

The recorded size and fee, which came from a second endpoint:

```
curl -s -X POST https://eu-api.koios.rest/api/v1/tx_info \
     -H 'Content-Type: application/json' \
     -d '{"_tx_hashes":["<the tx field>"],"_inputs":false,"_metadata":false,"_assets":false,
          "_withdrawals":false,"_certs":false,"_scripts":false,"_bytecode":false}'
```

Every one of the 3773 is in epoch 655, which is why a single parameter set covers the corpus.

The parameters:

```
curl -s 'https://eu-api.koios.rest/api/v1/epoch_params?_epoch_no=655'
```
