<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Primitives\TransactionOutput;

/**
 * THE SEAM. How a selection's surplus becomes change outputs.
 *
 * This interface is the whole of what selection knows about change, and it is deliberately the whole of it. The
 * change algorithm is specified separately, from the UnFrackIt work, and arrives as a language-neutral
 * specification with a committed vector set. Nothing in this branch invents one, because a change algorithm written
 * to taste and then replaced is two algorithms to test and one set of vectors that only ever matched the second.
 *
 * What is settled here, and what the specification will not have to argue with:
 *
 * - The surplus handed in is a ValueBag, so it carries the tokens as well as the lovelace. A strategy that returns
 *   outputs holding less than the surplus is burning somebody's tokens, and SurplusPreserved is the test that says
 *   so regardless of which strategy is installed.
 * - Every output a strategy returns has to satisfy the minimum UTxO for its own serialized size, and its value has
 *   to be inside maxValueSize. Both are computable from LedgerParameters, which is why the parameters are handed
 *   in rather than assumed.
 * - A strategy may return no outputs. A surplus too small to make a change output that meets the minimum is
 *   normally given to the fee, and the fee fixed point then has to run again over a transaction one output shorter.
 *   Returning an empty list is how a strategy says that.
 * - Nothing here decides how many outputs there should be, how they are shaped, or how assets are spread across
 *   them. That is exactly the decision the specification makes.
 *
 * To install the specified algorithm: write a class implementing this interface, and pass it wherever
 * SingleChangeOutput is passed today. Nothing else in this namespace changes, and nothing outside it refers to the
 * implementing class by name.
 */
interface ChangeStrategy
{
    /**
     * Turn a surplus into change outputs at the given address.
     *
     * @param  ValueBag  $surplus  everything the inputs held beyond the target, lovelace and tokens alike
     * @param  string  $changeAddress  raw address bytes, not bech32
     * @return list<TransactionOutput> in the order they are to be written; may be empty
     */
    public function change(ValueBag $surplus, string $changeAddress, LedgerParameters $parameters): array;
}
