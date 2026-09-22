# Two throwaway extended keys, and what cardano-signer and cardano-cli said about them

**These keys are test fixtures. They have never held funds and must never be sent any.** The mnemonic they come
from, in `mnemonic.txt`, was generated for this directory by cardano-signer and exists nowhere else. Anyone reading
this repository holds them, so anything sent to their addresses can be taken by anyone.

## What is here

| File | What it is |
| --- | --- |
| `mnemonic.txt` | The 24 words both keys are derived from. |
| `payment.skey`, `payment.vkey` | The CIP-1852 payment key at `1852H/1815H/0H/0/0`, typed `PaymentExtendedSigningKeyShelley_ed25519_bip32`. |
| `policy.skey`, `policy.vkey` | The CIP-1855 policy key at `1855H/1815H/0H`, typed `ExtendedSigningKeyShelley_ed25519_bip32`. |
| `vectors.json` | Per key: the verification key, its key hash, an enterprise address, a transaction witness and six signatures. |
| `generate.sh` | Rebuilds all of the above from `mnemonic.txt`. |

The signing key files hold the 128-byte extended key cardano-signer writes: kL, kR, the verification key and the
chain code, 32 bytes each, behind a `5880` CBOR head.

## Where each value came from

Nothing in this directory was produced by this package. Every value came from one of two tools:

- **cardano-signer 1.32.0** derived both keys from the mnemonic and made every signature in `signatures`. It then
  verified each signature against the verification key, and `cardano_signer_verify` records the answer. It also
  signed with each key while given the enterprise address in `enterprise_address`, which it does only when that
  address carries the hash of the key it signs with.
- **cardano-cli 10.7.0.0** derived the verification key again from each signing key, printed `key_hash`, built
  `enterprise_address`, and wrote `transaction_witness` over the body of the mainnet transaction
  `917b34247b18b94ed80afba9aec55750a78de52bfc8e4ee4d8f53f549041a5ad`, which is `plain-ada-payment` in the
  transaction corpus.

cardano-cli reads extended keys only under their Payment label, so `generate.sh` relabels a scratch copy of the
policy key before handing it over. The bytes it signs with are the bytes in `policy.skey`.

The messages are one byte, 32 zero bytes, 32 `ff` bytes, the body hash above, a line of text, and the 256 byte
values in order. cardano-signer refuses to verify an empty message, so there is no empty one.

## What the tests assert

`ExtendedSigningKeyTest` signs every message with each key and asserts the signature is cardano-signer's, byte for
byte, and that it verifies against the vkey file. It asserts the verification key and key hash are cardano-cli's,
and that the witness `TransactionSigner` makes over the corpus body is the one cardano-cli made. An extended
signature is deterministic, so byte equality is the claim, and verifying is the weaker check behind it.

**When one of those fails, the fixture is not the thing to change.** Every value here came from outside this
package, and editing one to match the output is how a test like this stops meaning anything.

## Regenerating

```
tests/fixtures/cardano-signer/generate.sh
```

It needs cardano-signer, cardano-cli and jq on the path, or `CARDANO_SIGNER` and `CARDANO_CLI` set to them. The keys
and signatures are deterministic, so a rerun rewrites the same files unless a tool has changed what it produces.
