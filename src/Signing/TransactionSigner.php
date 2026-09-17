<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Signing;

use Cardano\Transaction\Exception\SigningException;
use Cardano\Transaction\Hash\Blake2b;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionBody;
use Cardano\Transaction\Primitives\VkeyWitness;

/**
 * What gets signed, and what the signature is attached to.
 *
 * **A Cardano witness signs the transaction hash, not the transaction.** The message handed to Ed25519 is the
 * thirty-two byte blake2b-256 of the body, and nothing else: not the body bytes, not the whole transaction, not the
 * hash of the hash. Getting this wrong is not a crash. Signatures made over the wrong message verify perfectly
 * against each other, so a test suite that only ever checks its own signatures against its own keys passes, and
 * every transaction is refused by the node. That is why the two checks this package makes are both against something
 * it did not produce: the RFC 8032 vectors pin the primitive, and the committed corpus pins the message, because
 * every vkey witness on a real transaction verifies against the hash of the body we rebuilt for it.
 *
 * **The order is build, measure, sign.** The fee is a field of the body, and the signatures are over the body, so a
 * signature made before the fee settles is a signature over a body that no longer exists. Sign last, on the body
 * that is going to be submitted, and never edit a body after signing it: there is deliberately no method here that
 * takes a signed transaction and changes a field.
 */
final class TransactionSigner
{
    private function __construct() {}

    /**
     * One witness over this body from this key.
     */
    public static function witness(TransactionBody $body, SigningKey $key): VkeyWitness
    {
        return self::witnessForHash($body->hash(), $key);
    }

    /**
     * One witness over a body hash that has already been computed.
     *
     * The length is checked rather than assumed. A caller that passed the body bytes instead of their hash would
     * otherwise get a perfectly well formed witness over the wrong message, and the first thing to notice would be
     * a node.
     */
    public static function witnessForHash(string $bodyHash, SigningKey $key): VkeyWitness
    {
        if (strlen($bodyHash) !== Blake2b::DIGEST_TRANSACTION) {
            throw new SigningException(sprintf(
                'A transaction hash is %d bytes, got %d. A witness signs the hash of the body, not the body.',
                Blake2b::DIGEST_TRANSACTION,
                strlen($bodyHash)
            ));
        }

        return VkeyWitness::of($key->publicKey(), $key->sign($bodyHash));
    }

    /**
     * A signed transaction: the same body, the same auxiliary data, and a witness for each key added to the
     * witnesses already there.
     *
     * Everything already in the witness set is kept, and so is every signature on it that verifies against this
     * body. A native script spend carries the script beside the signatures, and dropping it here would produce a
     * transaction that is correctly signed and refused for having no witness for the script that guards the input.
     * A signature another party already put on the transaction is no different: a two-of-two branch is signed by
     * each party in turn, so replacing the set rather than adding to it hands the second signer a transaction the
     * first one's signature has quietly left.
     *
     * The measuring witnesses a fee is settled against are the one thing that does not survive, and they identify
     * themselves: they hold a signature of zeroes, which cannot verify. So the same rule both keeps a co-signer's
     * work and swaps every measuring witness for the real one, and the signed transaction is still exactly the
     * size the fee was computed for.
     *
     * Duplicate keys are refused, counting the witnesses that arrived as well as the keys given here. Two witnesses
     * from one key is not more signed than one; it is a hundred and one wasted bytes, a fee computed against a
     * witness count that does not match the set, and on some node versions a refusal. Signing twice with the same
     * key is therefore an error rather than a silent no-op, because it is usually a sign that the caller believes a
     * signature is missing when it is already there.
     */
    public static function sign(Transaction $transaction, SigningKey ...$keys): Transaction
    {
        if ($keys === []) {
            throw new SigningException('Signing a transaction takes at least one key.');
        }

        $seen = [];
        $carried = [];
        $witnesses = [];
        $hash = $transaction->body->hash();

        // A witness already on the transaction is kept when it verifies against this body, and dropped when it
        // does not. That single rule covers both of the ways a witness set arrives here. Measuring witnesses hold
        // a signature of zeroes precisely so that they cannot verify, so the fee settled against their size is
        // still the size of the transaction that gets submitted. A signature from another party does verify, and
        // it has to survive, because a two-of-two branch is signed by each party in turn. Anything that verifies
        // against a different body cannot make this transaction valid whether it is kept or not, and keeping it
        // would put the fee back out by a hundred and one bytes.
        foreach ($transaction->witnessSet->vkeyWitnesses() as $witness) {
            if (! $witness->verifies($hash)) {
                continue;
            }

            $carried[$witness->vkeyHex()] = true;
            $seen[$witness->vkeyHex()] = true;
            $witnesses[] = $witness;
        }

        foreach ($keys as $key) {
            $publicKey = $key->publicKeyHex();

            if (isset($carried[$publicKey])) {
                throw new SigningException(sprintf(
                    'The key %s has already witnessed this transaction; a transaction carries one witness per key. '
                    .'Signing again with a key whose witness is already there is usually a sign that the caller '
                    .'believes a signature was lost.',
                    $publicKey
                ));
            }

            if (isset($seen[$publicKey])) {
                throw new SigningException(sprintf(
                    'The key %s was given twice; a transaction carries one witness per key.',
                    $publicKey
                ));
            }

            $seen[$publicKey] = true;
            $witnesses[] = self::witnessForHash($hash, $key);
        }

        return $transaction->withWitnessSet($transaction->witnessSet->withVkeyWitnesses($witnesses));
    }

    /**
     * Whether every vkey witness on a transaction verifies against that transaction's own body hash.
     *
     * This is the check the node makes, less the question of whether those keys are the ones the inputs required,
     * which needs the outputs being spent and so cannot be answered from the transaction alone.
     */
    public static function witnessesVerify(Transaction $transaction): bool
    {
        $hash = $transaction->body->hash();

        foreach ($transaction->witnessSet->vkeyWitnesses() as $witness) {
            if (! $witness->verifies($hash)) {
                return false;
            }
        }

        return true;
    }
}
