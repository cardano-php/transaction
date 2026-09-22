#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Adam Dean
# SPDX-License-Identifier: Apache-2.0
#
# Rebuilds vectors.json in this directory from the throwaway mnemonic in mnemonic.txt, with cardano-signer and
# cardano-cli and nothing from this package. See README.md for what each value is and which tool produced it.

set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
signer="${CARDANO_SIGNER:-cardano-signer}"
cli="${CARDANO_CLI:-cardano-cli}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cd "$here"

"$signer" keygen --path payment --mnemonics mnemonic.txt --out-skey payment.skey --out-vkey payment.vkey > /dev/null
"$signer" keygen --path 1855H/1815H/0H --mnemonics mnemonic.txt --out-skey policy.skey --out-vkey policy.vkey > /dev/null

# The body cardano-cli witnesses: a mainnet transaction already in the corpus, whose body hash the suite recomputes.
tx_fixture="../cardano-tx/chain/plain-ada-payment.hex"
printf '{"type":"Tx ConwayEra","description":"","cborHex":"%s"}' "$(tr -d '\n' < "$tx_fixture")" > "$work/tx.json"
body_hash="$("$cli" conway transaction txid --tx-file "$work/tx.json" | tr -d '"{} \n' | sed 's/^txhash://')"

# cardano-signer verify refuses empty data, so every message is at least one byte long.
messages=(
    "72"
    "0000000000000000000000000000000000000000000000000000000000000000"
    "ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"
    "$body_hash"
    "$(printf 'Test fixture only. This key has never held funds.' | od -An -tx1 | tr -d ' \n')"
    "$(for i in $(seq 0 255); do printf '%02x' "$i"; done)"
)

key_json() {
    local name="$1" path="$2"
    local skey="$name.skey"

    # cardano-cli reads only the Payment label, so the policy key is relabelled in a scratch copy. The bytes are the
    # same bytes; only the type string differs.
    jq '.type = "PaymentExtendedSigningKeyShelley_ed25519_bip32"' "$skey" > "$work/$name.skey"
    "$cli" conway key verification-key --signing-key-file "$work/$name.skey" --verification-key-file "$work/$name.xvkey"
    "$cli" conway key non-extended-key --extended-verification-key-file "$work/$name.xvkey" \
        --verification-key-file "$work/$name.vkey"

    local public_key key_hash address witness
    public_key="$(jq -r '.cborHex[4:]' "$work/$name.vkey")"
    key_hash="$("$cli" address key-hash --payment-verification-key-file "$work/$name.vkey")"
    address="$("$cli" conway address build --payment-verification-key-file "$work/$name.vkey" --mainnet)"

    # cardano-signer refuses to sign when the address it is given does not carry the hash of the key it signs with.
    "$signer" sign --data-hex 00 --secret-key "$skey" --address "$address" > /dev/null

    "$cli" conway transaction witness --tx-body-file "$work/tx.json" --signing-key-file "$work/$name.skey" \
        --mainnet --out-file "$work/$name.witness"
    witness="$(jq -r '.cborHex' "$work/$name.witness")"

    local signatures="[]" message signature verified
    for message in "${messages[@]}"; do
        signature="$("$signer" sign --data-hex "$message" --secret-key "$skey" --signature-only)"
        verified="$("$signer" verify --data-hex "$message" --signature "$signature" --public-key "$public_key" || true)"
        signatures="$(jq -c --arg m "$message" --arg s "$signature" --arg v "$verified" \
            '. + [{message: $m, signature: $s, cardano_signer_verify: ($v == "true")}]' <<< "$signatures")"
    done

    jq -n --arg path "$path" --arg skey "$skey" --arg vkey "$name.vkey" --arg pk "$public_key" \
        --arg kh "$key_hash" --arg addr "$address" --arg w "$witness" --argjson sigs "$signatures" \
        '{derivation_path: $path, skey_file: $skey, vkey_file: $vkey, public_key: $pk, key_hash: $kh,
          enterprise_address: $addr, transaction_witness: $w, signatures: $sigs}'
}

payment="$(key_json payment 1852H/1815H/0H/0/0)"
policy="$(key_json policy 1855H/1815H/0H)"

jq -n --arg signer "$("$signer" help 2>&1 | head -1 | sed 's/\x1b\[[0-9;]*m//g')" \
    --arg cli "$("$cli" --version | head -1)" --arg tx "$tx_fixture" --arg bh "$body_hash" \
    --argjson payment "$payment" --argjson policy "$policy" \
    '{source: {generated_by: "generate.sh", cardano_signer: $signer, cardano_cli: $cli,
      note: "Every value here was produced by cardano-signer or cardano-cli from mnemonic.txt. Nothing was produced by this package. The keys are test fixtures and have never held funds."},
      transaction: {fixture: $tx, body_hash: $bh},
      keys: {payment: $payment, policy: $policy}}' > vectors.json
