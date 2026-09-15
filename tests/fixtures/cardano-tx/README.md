# Cardano transaction corpus

Real mainnet transactions, committed as hex, so the suite runs with no network. Each one is named in
`manifest.json` with the hash it was fetched by, the URL it came from, the request body that fetched it and the
shapes it covers.

## Where these came from

Koios, mainnet, on 15 September 2026. The documented host is `api.koios.rest`; `eu-api.koios.rest` is a mainnet
instance of the same service and is the host the fetch reached.

Refetching any one of them:

```
curl -s -X POST https://eu-api.koios.rest/api/v1/tx_cbor \
     -H 'Content-Type: application/json' \
     -d '{"_tx_hashes":["917b34247b18b94ed80afba9aec55750a78de52bfc8e4ee4d8f53f549041a5ad"]}'
```

The `cbor` field of the reply is the contents of the matching file under `chain/`.

## How they were chosen

By shape. A pool of 1616 mainnet transactions was gathered three ways:

- `GET /blocks` for recent block hashes, then `POST /block_txs` over them, for the general population;
- `GET /tx_by_metalabel?_label=721` for CIP-25 mints;
- `GET /tx_by_metalabel?_label=674` for CIP-20 messages.

Every one was then decoded and classified. The whole pool decodes, round trips byte for byte, hashes to the value
it was fetched by, verifies every vkey witness against that hash, and matches every auxiliary data hash. The
thirteen committed here are the smallest transactions in the pool that between them carry every shape
`manifest.json` describes.

Native script spends were separated from minting policies without asking the provider anything further. A native
script in the witness set whose blake2b-224 hash is not among the policy ids in the mint field cannot be witnessing
a mint, and with no certificates and no withdrawals in the transaction the only thing left for it to witness is a
spend. `native-script-spend` carries three such scripts, and Koios `tx_info` confirms three of its six inputs sit at
`addr1w` script addresses.

## What is in each directory

`chain/` holds transactions the ledger accepted. Every one has to decode, re-encode to the same bytes, hash to the
value it was fetched by, verify each of its vkey witnesses against that hash, and match its auxiliary data hash.

`negative/` holds bytes that must be refused. Each was made by changing one thing in a named chain fixture, and the
manifest says what was changed and why refusing it matters. They exist because every chain fixture is a transaction
the ledger already accepted, so the corpus on its own cannot show that the decoder refuses anything.

`perturbed/` holds one transaction that was never on chain: the plain payment with its fee raised by one lovelace.
It decodes, because it is well-formed. Its hash is not the hash of the fixture it came from, and the witness that
signed the original body does not verify against it. A decoder that returned a stored hash would pass every other
fixture and fail this one.

## Adding to the corpus

Fetch by `POST /tx_cbor`, write the hex to `chain/<id>.hex`, and add the entry to `manifest.json` with its hash,
source URL, request body and shapes. `TransactionCorpusTest` reads the manifest rather than the directory, and
`FixtureManifestTest` fails if the two disagree, so a file added without an entry is caught rather than ignored.
