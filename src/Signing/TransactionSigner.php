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
     * A signed transaction: the same body, the same auxiliary data, and a witness set carrying one witness per key.
     *
     * Everything already in the witness set other than the vkey witnesses is kept. A native script spend carries the
     * script beside the signatures, and dropping it here would produce a transaction that is correctly signed and
     * refused for having no witness for the script that guards the input.
     *
     * Duplicate keys are refused. Two witnesses from one key is not more signed than one; it is a hundred and one
     * wasted bytes, a fee computed against a witness count that does not match the set, and on some node versions a
     * refusal.
     */
    public static function sign(Transaction $transaction, SigningKey ...$keys): Transaction
    {
        if ($keys === []) {
            throw new SigningException('Signing a transaction takes at least one key.');
        }

        $seen = [];
        $witnesses = [];
        $hash = $transaction->body->hash();

        foreach ($keys as $key) {
            $publicKey = $key->publicKeyHex();

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
