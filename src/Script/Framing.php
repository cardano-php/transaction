<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Script;

use Cardano\Transaction\Cbor\SequenceForm;

/**
 * Which of the two ways a list of sub-scripts is framed.
 *
 * The same native script has two valid CBOR encodings, and a container holding 24 or more sub-scripts therefore has
 * two valid script hashes, two valid addresses and two valid governance identifiers. Neither is canonical. The ledger
 * accepts both, the CDDL constrains structure rather than framing, and the ledger hashes whichever bytes it was
 * handed.
 *
 * cardano-binary, the serialization library underneath cardano-ledger, cardano-api, cardano-cli and cardano-node,
 * writes a definite-length array up to 23 elements and an indefinite-length array from 24. cardano-addresses
 * reimplements the same rule independently. cardano-serialization-lib, MeshJS and most of the JavaScript ecosystem
 * write a definite-length array at every size, as does the Ledger hardware wallet's script hash builder.
 *
 * 24 is where a CBOR array header stops fitting in the head byte and needs a following length byte, which is the
 * point at which a streaming encoder that does not know its length in advance starts preferring the indefinite form.
 *
 * A caller building a script from JSON or from the builders picks the side it is talking to. The default is the one
 * cardano-cli writes, so a hash derived here from a script file is the hash `cardano-cli transaction policyid` prints
 * for that file. A caller deriving an address a JavaScript wallet will also derive asks for the other.
 *
 * Below 24 sub-scripts the two are byte-identical, which is why almost no real script ever meets this: a 3-of-5
 * treasury, a 2-of-3 DRep and a 7-of-10 committee are all far under the line. It appears all at once when a cohort
 * grows past 23.
 */
enum Framing
{
    /**
     * A definite-length array at every size.
     *
     * cardano-serialization-lib, MeshJS and most of the JavaScript ecosystem, so this is the framing already recorded
     * on chain for scripts built by wallets.
     */
    case Definite;

    /**
     * Definite up to 23 sub-scripts and indefinite from 24, which is what cardano-binary writes.
     *
     * cardano-node and cardano-cli serialize through that library, so this is the framing to match when a hash has to
     * agree with what the cli prints.
     */
    case CardanoBinary;

    /**
     * The form a list of $itemCount sub-scripts takes under this framing.
     */
    public function formFor(int $itemCount): SequenceForm
    {
        return match ($this) {
            self::Definite => SequenceForm::definite(),
            self::CardanoBinary => SequenceForm::forLedgerLength($itemCount),
        };
    }

    /**
     * Whether a list of $itemCount sub-scripts written in $form is written the way this framing writes it.
     */
    public function frames(SequenceForm $form, int $itemCount): bool
    {
        return $form->indefinite === $this->formFor($itemCount)->indefinite;
    }
}
