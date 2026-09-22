# RFC 9496 ristretto255 test vectors

The multiples of the generator and the invalid encodings from Appendix A of RFC 9496, the document that specifies
ristretto255.

## Where these came from

`rfc9496-vectors.json` was extracted from https://www.rfc-editor.org/rfc/rfc9496.txt, fetched on 22 September 2026.
Appendix A.1 lists the encodings of 0 to 15 times the generator. Appendix A.2 lists 29 encodings a decoder must
refuse, in five groups: non-canonical field encodings, negative field elements, a non-square x squared, a negative
x times y, and s = -1. Each invalid encoding keeps the name of its group as `reason`.

## Why this package needs them

Signing with an extended key gets R, and the verification key, from libsodium as ristretto255 encodings, and
`Edwards25519` decodes them to the Edwards points a signature and a witness carry. `Ristretto255VectorTest` checks
the decoder against these vectors. Each multiple has to decode to k times the base point, computed from the curve
equation alone. Each invalid encoding has to be refused at the step RFC 9496 section 4.3.1 names.

## Refetching

```
curl -s https://www.rfc-editor.org/rfc/rfc9496.txt
```

Appendix A begins at the heading `Appendix A.  Test Vectors for ristretto255`. If a vector in this file ever
disagrees with that document, this file is wrong.
