# Era summaries and known blocks

What a slot number means, on mainnet and on preprod, and ten real blocks to check the arithmetic against.

## Why the era summaries do not come from `/era_summaries`

Koios has an endpoint of that name and it is not this. `GET /era_summaries` returns a list of hard forks with protocol
versions and first block times, and carries neither a slot length nor an era's start slot, so nothing can be
converted with it. The Ouroboros era summaries, which do carry both, are reached through Koios's Ogmios endpoint:

```
curl -s -X POST https://eu-api.koios.rest/api/v1/ogmios \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","method":"queryLedgerState/eraSummaries","params":{}}'
```

Era times there are seconds since the network's system start rather than Unix time, so the system start is recorded
alongside them, from:

```
curl -s -X POST https://eu-api.koios.rest/api/v1/ogmios \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","method":"queryNetwork/startTime","params":{}}'
```

Mainnet starts at 2017-09-23T21:44:51Z and preprod at 2022-06-01T00:00:00Z. Both are recorded in the file as written
and as a Unix timestamp.

## The blocks

Each block was fetched with `GET /blocks?block_height=eq.<height>` and carries the slot and the timestamp the chain
reports for it. The six mainnet heights and four preprod ones were chosen to straddle every boundary a converter can
get wrong: a Byron block, where slots are twenty seconds long; the first Shelley block, where they become one second;
and blocks under four later protocol versions.

Before this file was written, every block's timestamp was converted to a slot by arithmetic written out in the fetch
script itself, and compared against the `abs_slot` the chain reports. A disagreement would have stopped the fetch.
The chain decides which slot a timestamp belongs to, and nothing in this repository gets a vote.

## The forecast horizon

The last era's `end` is not a date the era is known to finish on. It is how far ahead the ledger will commit to the
current slot length, which is a few days. Every campaign expiry is months past it. A conversion beyond the horizon
uses the current era's parameters and is correct unless a hard fork changes the slot length first, which has not
happened since Shelley. `EraSummaries::isBeyondHorizon()` is how a caller can tell that an answer rests on that.
