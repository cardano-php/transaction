# Extended signatures from an independent reference

Fifteen extended BIP32-Ed25519 signatures over kL values that no wallet derivation and no libsodium seed produces,
each with the verification key and the message it was made over.

## Why these exist

The cardano-signer keys under `../cardano-signer` and the libsodium-expanded seeds cover the kL values real keys
have. A root key's kL is clamped, a derived key's kL stays below 2^255 in practice, and a libsodium scalar always has
bit 254 set. The signing path reduces kL modulo the group order before libsodium sees it, and these vectors check
that reduction on values the other fixtures never reach. Each shape has three vectors:

| Shape | kL |
| --- | --- |
| `top bit set` | at or above 2^255 |
| `bit 254 clear` | below 2^254 |
| `root clamped` | the shape of a BIP32-Ed25519 root key |
| `below L` | smaller than the group order, so reduction changes nothing |
| `any multiple of eight` | random apart from the three low bits |

Every kL is a multiple of eight, because every BIP32-Ed25519 kL is and `SigningKey::fromExtended()` refuses any
other.

## Where they came from

`generate.py` computes every value with Python integers and `hashlib`. It shares no code with this package: its
point arithmetic is the extended-coordinate formula from RFC 8032 section 5.1.4, and it multiplies by kL as a whole
integer rather than reducing it first. kL and kR are derived from fixed labels, so the file is the same every time it
is generated. The keys have never held funds.

The same signer reproduces all twelve cardano-signer signatures under `../cardano-signer`, byte for byte, which is
the check that it signs the way cardano-signer does.

## Regenerating

```
python3 tests/fixtures/ed25519-bip32/generate.py
```

It needs Python 3 and nothing else, and writes `vectors.json` beside itself.
