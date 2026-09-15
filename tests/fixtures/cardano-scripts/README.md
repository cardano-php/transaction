# Native scripts, with the hashes the chain knows them by

Ten real mainnet native scripts, each recorded with the script hash the ledger indexed it under.

## Why these and not scripts written here

A test that built a script, hashed it, and compared the hash against a value this repository computed would agree
with itself whatever the encoder did. A `/script_info` reply is not that. The provider returns the script and the
hash separately, and the hash is the one the chain assigned when the script was used. Reproducing it means the
encoding, the language tag byte and the digest length are all right at once.

## Where these came from

Koios, mainnet, on 15 September 2026. The documented host is `api.koios.rest`; `eu-api.koios.rest` is a mainnet
instance of the same service and is the host the fetch reached.

```
curl -s -X POST https://eu-api.koios.rest/api/v1/script_info \
     -H 'Content-Type: application/json' \
     -d '{"_script_hashes":["0898aca23cdf56cafd3dae6c5dff8bf235e23d38038ba94c6b663f45"]}'
```

`script_hash`, `creation_tx_hash` and `script` are the provider's own fields, unmodified.

## How they were chosen

Four are the native scripts carried in the witness sets of the transaction corpus under `../cardano-tx`, so the two
fixture sets overlap and a change that broke one would show up in both.

The other six were picked by shape from a pool of 4000 taken from `/native_script_list`. Every script in the pool was
classified by which constructors it uses and how deep it nests, and the six are the set that covers all of `sig`,
`all`, `any`, `atLeast`, `before` and `after` with the fewest scripts, plus the smallest example of a bare `sig` and
a bare `before` and the deepest trees in the pool. Of the 4000, 2689 are `all` over `sig` and `before`, 1276 are a
bare `sig`, and only four use `atLeast` at all.

## What is derived, and by what

`cbor` and the two addresses beside each script were not produced by the code under test:

- `cbor` comes from a stand-alone CBOR encoder written into the fetch script, following the Shelley-MA CDDL;
- every entry was checked before this file was written by hashing that CBOR with Python's hashlib blake2b at a
  digest length of twenty-eight bytes, prefixed with the `0x00` language tag byte, and comparing against the
  provider's `script_hash`. A script whose reference hash disagreed with the chain would have stopped the fetch
  rather than been written out;
- `enterprise_address_mainnet` and `enterprise_address_preprod` come from the BIP-173 reference bech32
  implementation over the `0x71` and `0x70` headers.

## Adding to this file

Fetch by `POST /script_info`, check the reference hash against the provider's before writing anything, and record
which constructors the script uses. `NativeScriptCorpusTest` reads the file rather than counting on its contents, and
asserts that the ten between them still cover all six constructors, so a script added without its constructors
recorded is caught rather than ignored.
