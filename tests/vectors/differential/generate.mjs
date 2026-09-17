// Builds every differential vector with Mesh's @meshsdk/core-cst and writes vectors.json.
//
// Run it with `node tests/vectors/differential/generate.mjs` from the package root. It writes vectors.json beside
// itself and touches nothing else. It is committed so that the vectors can be regenerated and re-reviewed, not so
// that they are regenerated on every run: the test suite reads the committed JSON and never starts Node, because a
// suite that shelled out to a second toolchain would be measuring whether that toolchain installed cleanly this
// morning rather than whether the PHP is right.
//
// Every case here is described twice. `spec` is the transaction in neutral terms, and it is what the PHP builder is
// handed. `mesh` is what Mesh made of the same description. Nothing in `spec` comes from Mesh, so the two sides are
// building from one description rather than one side copying the other's answer.
//
// There are no private keys anywhere in this file or in what it writes. Where a witness set is needed, it holds
// witnesses of the right length filled with zeroes: those are what a fee is measured against before any signing
// happens, and a signature of zeroes cannot verify, so a vector can never be mistaken for a signed transaction.

import { readFileSync, writeFileSync } from 'node:fs';
import { Cardano, Crypto, Serialization } from '@meshsdk/core-cst';

const S = Serialization;

const addressVectors = JSON.parse(
  readFileSync(new URL('../../fixtures/cardano-addresses/cip19-vectors.json', import.meta.url), 'utf8'),
);

const addressOf = (network, type) => {
  const found = addressVectors.addresses.find((a) => a.network === network && a.address_type === type);
  if (!found) throw new Error(`No CIP-19 vector for ${network} type ${type}`);
  return found.bytes;
};

// Every address below is a published CIP-19 test vector, read out of the committed fixture rather than typed here.
const BASE = addressOf('testnet', 0);
const ENTERPRISE = addressOf('testnet', 6);
const SCRIPT_BASE = addressOf('testnet', 1);

const KEY_HASH_A = addressVectors.keys.payment_key_hash;
const KEY_HASH_B = addressVectors.keys.stake_key_hash;
const POLICY_A = addressVectors.keys.script_hash;
const POLICY_B = 'c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d5400';

const txId = (byte) => byte.repeat(32);

// ---------------------------------------------------------------- spec to Mesh

const assetId = (policyId, name) => Cardano.AssetId(policyId + name);

const meshValue = (output) => {
  const value = { coins: BigInt(output.coin) };
  if (output.assets && output.assets.length > 0) {
    value.assets = new Map(
      output.assets.flatMap((bundle) =>
        bundle.assets.map((asset) => [assetId(bundle.policy_id, asset.name), BigInt(asset.quantity)]),
      ),
    );
  }
  return value;
};

const meshMint = (bundles) =>
  new Map(
    bundles.flatMap((bundle) => bundle.assets.map((asset) => [assetId(bundle.policy_id, asset.name), BigInt(asset.quantity)])),
  );

const meshBody = (spec) => {
  const core = {
    fee: BigInt(spec.fee),
    inputs: spec.inputs.map((i) => ({ index: i.index, txId: Cardano.TransactionId(i.transaction_id) })),
    outputs: spec.outputs.map((o) => ({
      address: Cardano.Address.fromBytes(o.address).toBech32(),
      value: meshValue(o),
    })),
  };

  const interval = {};
  if (spec.validity_interval_start != null) interval.invalidBefore = Cardano.Slot(Number(spec.validity_interval_start));
  if (spec.ttl != null) interval.invalidHereafter = Cardano.Slot(Number(spec.ttl));
  if (Object.keys(interval).length > 0) core.validityInterval = interval;

  if (spec.mint) core.mint = meshMint(spec.mint);
  if (spec.required_signers) core.requiredExtraSignatures = spec.required_signers.map((h) => Crypto.Ed25519KeyHashHex(h));
  if (spec.network_id != null) core.networkId = spec.network_id;
  if (spec.collateral) {
    core.collaterals = spec.collateral.map((i) => ({ index: i.index, txId: Cardano.TransactionId(i.transaction_id) }));
  }
  if (spec.reference_inputs) {
    core.referenceInputs = spec.reference_inputs.map((i) => ({
      index: i.index,
      txId: Cardano.TransactionId(i.transaction_id),
    }));
  }
  if (spec.auxiliary_data_hash) core.auxiliaryDataHash = Crypto.Hash32ByteBase16(spec.auxiliary_data_hash);

  return S.TransactionBody.fromCore(core);
};

// The cardano-cli JSON script form, which is what the PHP side reads, translated into Mesh's core shape.
const meshScript = (json) => {
  switch (json.type) {
    case 'sig':
      return { keyHash: Crypto.Ed25519KeyHashHex(json.keyHash), kind: Cardano.NativeScriptKind.RequireSignature };
    case 'all':
      return { kind: Cardano.NativeScriptKind.RequireAllOf, scripts: json.scripts.map(meshScript) };
    case 'any':
      return { kind: Cardano.NativeScriptKind.RequireAnyOf, scripts: json.scripts.map(meshScript) };
    case 'atLeast':
      return { kind: Cardano.NativeScriptKind.RequireNOf, required: json.required, scripts: json.scripts.map(meshScript) };
    case 'before':
      return { kind: Cardano.NativeScriptKind.RequireTimeBefore, slot: Cardano.Slot(json.slot) };
    case 'after':
      return { kind: Cardano.NativeScriptKind.RequireTimeAfter, slot: Cardano.Slot(json.slot) };
    default:
      throw new Error(`Unknown native script type ${json.type}`);
  }
};

const meshWitnessSet = (witnesses) => {
  const set = new S.TransactionWitnessSet();

  if (witnesses.dummy_signatures > 0) {
    const entries = [];
    for (let i = 0; i < witnesses.dummy_signatures; i++) {
      entries.push([Crypto.Ed25519PublicKeyHex('00'.repeat(32)), Crypto.Ed25519SignatureHex('00'.repeat(64))]);
    }
    set.setVkeys(S.CborSet.fromCore(entries, S.VkeyWitness.fromCore));
  }

  if (witnesses.native_scripts && witnesses.native_scripts.length > 0) {
    set.setNativeScripts(S.CborSet.fromCore(witnesses.native_scripts.map(meshScript), S.NativeScript.fromCore));
  }

  return set;
};

// Metadata in the neutral form: a list of {label, value} where value is already CBOR-shaped JSON.
const meshMetadatum = (value) => {
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return BigInt(value);
  if (Array.isArray(value)) return value.map(meshMetadatum);
  if (value && typeof value === 'object' && value.bytes) return Buffer.from(value.bytes, 'hex');
  if (value && typeof value === 'object' && value.map) {
    return new Map(value.map.map(([k, v]) => [meshMetadatum(k), meshMetadatum(v)]));
  }
  throw new Error(`Unhandled metadatum ${JSON.stringify(value)}`);
};

const meshAuxiliaryData = (metadata) =>
  S.AuxiliaryData.fromCore({
    blob: new Map(metadata.map((entry) => [BigInt(entry.label), meshMetadatum(entry.value)])),
  });

// ---------------------------------------------------------------- the cases

// A case asks for at most one dummy witness. Mesh models the vkey witnesses as a CborSet and collapses duplicates,
// and every dummy is the same 101 bytes of zeroes, so asking Mesh for two gives back one. That is Mesh being right
// about a set and is not a disagreement worth encoding as a vector: the PHP side has to write one entry per witness
// whatever they hold, because the whole purpose of a dummy is to make the transaction the size it will be once the
// real witnesses are in it. WitnessAssemblyTest covers the multiple-witness case directly, where there is no set
// semantics to argue with, and TransactionSigner refuses two witnesses from one key on the way out.
const dummyWitnesses = (count = 0, scripts = []) => ({ dummy_signatures: count, native_scripts: scripts });

const CAMPAIGN_SCRIPT = {
  type: 'any',
  scripts: [
    { keyHash: KEY_HASH_A, type: 'sig' },
    { scripts: [{ keyHash: KEY_HASH_B, type: 'sig' }, { slot: 84600000, type: 'after' }], type: 'all' },
  ],
};

const cases = [
  {
    id: 'plain-ada-payment',
    note: 'One input, one output, a fee. The smallest thing the claim path can send.',
    spec: {
      fee: '170000',
      inputs: [{ index: 0, transaction_id: txId('11') }],
      outputs: [{ address: BASE, coin: '1000000' }],
    },
    witnesses: dummyWitnesses(),
  },
  {
    id: 'two-inputs-change-and-ttl',
    note: 'Two inputs paying one recipient with change back, under an upper validity bound.',
    spec: {
      fee: '182903',
      inputs: [
        { index: 0, transaction_id: txId('11') },
        { index: 7, transaction_id: txId('22') },
      ],
      outputs: [
        { address: ENTERPRISE, coin: '2000000' },
        { address: BASE, coin: '48817097' },
      ],
      ttl: '123456789',
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'single-multi-asset-output',
    note: 'The airdrop shape: one output carrying lovelace and one token of one policy.',
    spec: {
      fee: '176721',
      inputs: [{ index: 1, transaction_id: txId('33') }],
      outputs: [
        {
          address: BASE,
          assets: [{ assets: [{ name: '4f4e424f4152', quantity: '1' }], policy_id: POLICY_A }],
          coin: '1400000',
        },
      ],
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'many-assets-one-policy',
    note: 'Several asset names under one policy, declared out of byte order so any reordering shows up.',
    spec: {
      fee: '190211',
      inputs: [{ index: 0, transaction_id: txId('44') }],
      outputs: [
        {
          address: BASE,
          assets: [
            {
              assets: [
                { name: '7a7a', quantity: '5' },
                { name: '00', quantity: '1' },
                { name: '4f4e424f4152', quantity: '99' },
                { name: '', quantity: '7' },
              ],
              policy_id: POLICY_A,
            },
          ],
          coin: '1600000',
        },
      ],
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'many-policies',
    note: 'Two policies in one output, the higher-sorting one declared first.',
    spec: {
      fee: '195533',
      inputs: [{ index: 0, transaction_id: txId('55') }],
      outputs: [
        {
          address: BASE,
          assets: [
            { assets: [{ name: '4f4e45', quantity: '1' }], policy_id: POLICY_A },
            { assets: [{ name: '54574f', quantity: '2' }], policy_id: POLICY_B },
          ],
          coin: '1700000',
        },
      ],
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'both-validity-bounds',
    note: 'A lower and an upper bound, which is the shape a campaign transaction is built with.',
    spec: {
      fee: '171441',
      inputs: [{ index: 2, transaction_id: txId('66') }],
      outputs: [{ address: BASE, coin: '3000000' }],
      ttl: '84600000',
      validity_interval_start: '84500000',
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'mint-required-signers-network-id',
    note: 'A mint with a required signer and an explicit network id, exercising the high body fields.',
    spec: {
      fee: '203567',
      inputs: [{ index: 3, transaction_id: txId('77') }],
      mint: [{ assets: [{ name: '4f4e424f4152', quantity: '1' }], policy_id: POLICY_A }],
      network_id: 0,
      outputs: [
        {
          address: SCRIPT_BASE,
          assets: [{ assets: [{ name: '4f4e424f4152', quantity: '1' }], policy_id: POLICY_A }],
          coin: '2000000',
        },
      ],
      required_signers: [KEY_HASH_A],
      ttl: '84600000',
      validity_interval_start: '84500000',
    },
    witnesses: dummyWitnesses(1, [CAMPAIGN_SCRIPT]),
  },
  {
    id: 'collateral-and-reference-inputs',
    note: 'The two input sets that are not the spend set, which land in fields 13 and 18.',
    spec: {
      collateral: [{ index: 0, transaction_id: txId('88') }],
      fee: '210000',
      inputs: [{ index: 0, transaction_id: txId('99') }],
      outputs: [{ address: BASE, coin: '5000000' }],
      reference_inputs: [{ index: 4, transaction_id: txId('aa') }],
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'max-uint64-asset-quantity',
    note: 'A token quantity at the ledger ceiling, which is larger than a PHP signed integer holds.',
    spec: {
      fee: '178123',
      inputs: [{ index: 0, transaction_id: txId('bb') }],
      outputs: [
        {
          address: BASE,
          assets: [{ assets: [{ name: '4d4158', quantity: '18446744073709551615' }], policy_id: POLICY_A }],
          coin: '1500000',
        },
      ],
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'cip20-message',
    note: 'A transaction message under label 674, hashed into the body and attached to the transaction.',
    metadata: [{ label: '674', value: { map: [['msg', ['Claimed with onboard.ninja']]] } }],
    spec: {
      fee: '185311',
      inputs: [{ index: 0, transaction_id: txId('cc') }],
      outputs: [{ address: BASE, coin: '2500000' }],
      ttl: '84600000',
    },
    witnesses: dummyWitnesses(1),
  },
  {
    id: 'cip25-mint-metadata',
    note: 'A CIP-25 mint: the metadata under label 721 and the mint field describing the same token.',
    metadata: [
      {
        label: '721',
        value: {
          map: [[POLICY_A, { map: [['ONBOARD', { map: [['name', 'Onboard Ninja'], ['image', 'ipfs://QmTest']] }]] }]],
        },
      },
    ],
    spec: {
      fee: '221999',
      inputs: [{ index: 0, transaction_id: txId('dd') }],
      mint: [{ assets: [{ name: '4f4e424f415244', quantity: '1' }], policy_id: POLICY_A }],
      outputs: [
        {
          address: BASE,
          assets: [{ assets: [{ name: '4f4e424f415244', quantity: '1' }], policy_id: POLICY_A }],
          coin: '1400000',
        },
      ],
      ttl: '84600000',
    },
    witnesses: dummyWitnesses(1, [CAMPAIGN_SCRIPT]),
  },
];

// The fee sits inside the thing it is charged on, and a CBOR unsigned integer has five widths, so the width of the
// fee field changes as the fee crosses 23, 255, 65535 and 4294967295. These are the boundaries the fixed point walks
// over, built on one unchanging body so that the only thing moving is the fee.
const FEE_BOUNDARIES = ['0', '23', '24', '255', '256', '65535', '65536', '4294967295', '4294967296'];

const feeBase = {
  inputs: [{ index: 0, transaction_id: txId('ee') }],
  outputs: [{ address: BASE, coin: '1000000' }],
};

// ---------------------------------------------------------------- generation

const built = cases.map((testCase) => {
  const spec = { ...testCase.spec };
  let auxiliaryData = null;

  if (testCase.metadata) {
    auxiliaryData = meshAuxiliaryData(testCase.metadata);
    spec.auxiliary_data_hash = Cardano.computeAuxiliaryDataHash(auxiliaryData.toCore());
  }

  const body = meshBody(spec);
  const witnessSet = meshWitnessSet(testCase.witnesses);
  const transaction = new S.Transaction(body, witnessSet, auxiliaryData ?? undefined);

  return {
    id: testCase.id,
    mesh: {
      auxiliary_data_cbor: auxiliaryData ? auxiliaryData.toCbor() : null,
      body_cbor: body.toCbor(),
      body_hash: body.hash(),
      transaction_cbor: transaction.toCbor(),
      transaction_id: transaction.getId(),
      witness_set_cbor: witnessSet.toCbor(),
    },
    metadata: testCase.metadata ?? null,
    note: testCase.note,
    spec,
    witnesses: testCase.witnesses,
  };
});

const fees = FEE_BOUNDARIES.map((fee) => {
  const body = meshBody({ ...feeBase, fee });

  return { body_cbor: body.toCbor(), body_hash: body.hash(), fee };
});

// Read straight off disk. Neither package exports ./package.json, and the version is what tells a reader of
// vectors.json which reference implementation produced it.
const packageVersion = (name) =>
  JSON.parse(readFileSync(new URL(`../../../node_modules/${name}/package.json`, import.meta.url), 'utf8')).version;

writeFileSync(
  new URL('vectors.json', import.meta.url),
  `${JSON.stringify(
    {
      cases: built,
      fee_field_widths: { base: feeBase, cases: fees },
      generator: {
        addresses: 'tests/fixtures/cardano-addresses/cip19-vectors.json, the published CIP-19 test vectors',
        generated_at: new Date().toISOString().slice(0, 10),
        node: process.version,
        packages: {
          '@cardano-sdk/core': packageVersion('@cardano-sdk/core'),
          '@meshsdk/core-cst': packageVersion('@meshsdk/core-cst'),
        },
        script: 'tests/vectors/differential/generate.mjs',
      },
    },
    null,
    2,
  )}\n`,
);

console.log(`Wrote ${built.length} cases and ${fees.length} fee widths.`);
