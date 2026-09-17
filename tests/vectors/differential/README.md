# Differential vectors: the same transaction, built by someone else

`vectors.json` holds eleven transactions and nine fee widths, each described in neutral terms and then built by
[`@meshsdk/core-cst`](https://www.npmjs.com/package/@meshsdk/core-cst) over `@cardano-sdk/core`. The PHP suite builds
the same transactions from the same descriptions and compares. `DifferentialVectorTest` is the reader.

## Why a second implementation

Round-tripping says the encoder and the decoder agree, which they would even if both were wrong in the same way.
The committed mainnet corpus in `tests/fixtures/cardano-tx` covers only the read direction: it says this package can
read and hash what the chain already has, not that what it writes is what another implementation would write.

Mesh is the reference because it is actively maintained, it is the serializer behind MeshJS, and it writes
transactions that mainnet accepts every day.

## Regenerating

```
node tests/vectors/differential/generate.mjs
```

The generator writes `vectors.json` beside itself and touches nothing else. It is committed so that the vectors
can be regenerated and the diff reviewed, not so that they are regenerated on every run. The test suite reads the
JSON and never starts Node: a suite that shelled out to a second toolchain would be reporting whether that
toolchain installed cleanly this morning.

Every address in the vectors is a published CIP-19 test vector, read out of
`tests/fixtures/cardano-addresses/cip19-vectors.json` rather than typed into the generator.

## No keys, no signatures

There is no private key in this directory and nothing here is signed. Where a case needs a witness set it holds
witnesses of the right length filled with zeroes, which is what a fee is measured against before anything is signed
and which no node will accept. `DifferentialVectorTest::test_no_vector_carries_a_usable_signature` asserts that on
every case, so a regenerated file that started carrying real signatures fails rather than quietly committing key
material.

A case asks for at most one witness. Mesh models the vkey witnesses as a set and collapses two identical dummies
into one. The PHP side writes one entry per witness whatever they hold, because the whole point of a dummy is to
make the transaction the size it will be once the real witnesses are in it. So the multiple-witness case is
asserted directly in `WitnessAssemblyTest` instead, and `TransactionSigner` refuses two witnesses from one key on
the way out.

## Structural or byte for byte

Both implementations are correct and two of their encoding choices differ. Neither difference changes what a
transaction does, both produce bytes the ledger accepts, and each changes the body hash.

| Field                     | Compared         | Why                                                                                                                                                                                                                                    |
| ------------------------- | ---------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Output container          | Structurally     | Babbage allows an output as a two-item array or as a map keyed by small integers. `TransactionOutput::create` writes the map form deliberately; Mesh writes the array form for an output with no datum and no script reference.         |
| Multiasset map order      | Structurally     | The ledger takes a multiasset map in any order. Mesh sorts canonically, by the encoded key, so shorter asset names first. The builder writes the order it is handed; `MultiAsset::canonical` produces Mesh's.                           |
| Everything else           | Byte for byte    | Body field set and order, input sets, fee and its width, both validity bounds, mint, required signers, network id, collateral, reference inputs, auxiliary data and its hash, witness set, and the four item transaction wrapper.       |

The byte level is not given up where a field is compared structurally:

- `test_a_body_built_with_mesh_outputs_is_byte_identical` rebuilds each body with the outputs decoded out of Mesh's
  own bytes and canonical asset ordering, and requires the result to be Mesh's body exactly, hash included. That says
  the two divergences above are the only places the implementations differ.
- `test_canonical_asset_order_reproduces_mesh_value_bytes` requires the value inside each output, and the mint field,
  to be Mesh's bytes exactly once the assets are in canonical order.
- `test_mesh_bytes_round_trip_through_this_decoder` requires Mesh's bytes to survive this package's decoder unchanged
  and to hash to the transaction id Mesh computed.

Two control assertions stop the structural comparison being vacuous:
`test_the_two_output_forms_are_not_the_same_bytes` and `test_canonical_asset_order_is_not_declaration_order` both
require the two arrangements to differ, so a normalization that quietly matched everything would fail.

## What the cases cover

| Case                               | What it exercises                                                          |
| ---------------------------------- | -------------------------------------------------------------------------- |
| `plain-ada-payment`                | One input, one output, a fee                                               |
| `two-inputs-change-and-ttl`        | Two inputs, change, an upper validity bound                                |
| `single-multi-asset-output`        | One policy, one asset: the airdrop shape                                   |
| `many-assets-one-policy`           | Four asset names declared out of canonical order, including the empty name |
| `many-policies`                    | Two policies, the higher-sorting one declared first                        |
| `both-validity-bounds`             | Lower and upper bound together                                             |
| `mint-required-signers-network-id` | Mint, required signer, explicit network id, a native script witness        |
| `collateral-and-reference-inputs`  | Fields 13 and 18, the two input sets that are not the spend set            |
| `max-uint64-asset-quantity`        | A quantity larger than a PHP signed integer holds                          |
| `cip20-message`                    | Auxiliary data hashed into the body and attached to the transaction        |
| `cip25-mint-metadata`              | Label 721 metadata beside the mint field describing the same token         |
| `fee_field_widths`                 | Nine fees spanning every width a CBOR unsigned integer has                 |

## What this does not prove

It does not prove either implementation is right about Cardano, only that they agree. What ties the agreement to the
chain is elsewhere: `tests/fixtures/cardano-tx` is real mainnet traffic, and every witness on it verifies against the
body hash this package computes, using keys nobody here holds.

It also says nothing about submission, fee adequacy against live parameters, or whether a node would accept any of
these transactions. Nothing in this directory has been near a network.
