# RFC 8032 Ed25519 test vectors

The five published Ed25519 vectors from section 7.1 of RFC 8032, the document that specifies the signature scheme
Cardano uses.

## Where these came from

`rfc8032-vectors.json` was extracted from https://www.rfc-editor.org/rfc/rfc8032.txt, fetched on 15 September 2026.
The RFC prints each vector as a secret key, a public key, a message and a signature, with the hex broken at
thirty-two digits to a line and the message length given in its label. The extraction rejoins the lines and keeps
that length beside each message, so a transcription that dropped a line is caught by the length rather than by a
signature that happens not to verify.

The five are the ones the RFC publishes: an empty message, a one byte message, a two byte message, a 1023 byte
message, and the SHA-512 of `abc` as a 64 byte message.

## Why they are not generated

Nothing in this file may be produced by the code that reads it. A vector computed with libsodium and then checked
against libsodium passes whatever libsodium does, including doing the wrong thing consistently. These were fixed in
2017 by a document with no knowledge of this repository, so a signature this repository produces either equals the
published one or does not.

**When a test here fails, the vector is not the thing to change.** A mismatch means either the transcription above
is wrong or the signing path is, and the first is checked by re-extracting from the RFC. Editing a vector to match
the output is how a test like this stops meaning anything.

## What they pin, and what they do not

They pin the primitive: that `sodium_crypto_sign_detached` over a given message with a given key produces the
signature Ed25519 specifies, that a 32 byte seed expands to the published verification key, and that verification
accepts the published signature.

They say nothing about Cardano. Which bytes a witness is taken over is a ledger question rather than an RFC 8032
one, and it is pinned somewhere else entirely. Every vkey witness on every transaction in
`tests/fixtures/cardano-tx` verifies against the body hash this package computes for it, and those signatures were
made by keys nobody here holds.

## Refetching

```
curl -s https://www.rfc-editor.org/rfc/rfc8032.txt
```

Section 7.1 begins at the heading `7.1.  Test Vectors for Ed25519`. If a vector in this file ever disagrees with
that document, this file is wrong.
