<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Selection;

use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;

/**
 * A placeholder change strategy: everything back to one address, split only where maxValueSize forces it.
 *
 * **This is not the change algorithm.** The change algorithm is specified separately, from the UnFrackIt work, and
 * arrives with a committed vector set. This class exists so the rest of the layer can be built and tested against
 * something that runs, and so the seam it plugs into is one that has been used rather than one that has only been
 * described. When the specification lands, its implementation replaces this class and nothing else moves.
 *
 * What it does is the least interesting correct thing: hand the whole surplus to ValuePacker and return what comes
 * back. That preserves every token, which is the one property no change algorithm may get wrong, and it satisfies
 * the minimum UTxO and maxValueSize on every output it returns. What it does not do is any of the work a real
 * change algorithm is for. It does not shape outputs for what they will be spent on next, does not consider what
 * the wallet looks like afterwards, and does not spread value to keep later selections cheap.
 *
 * It also does not decide what to do with a surplus too small to make a change output. It refuses, with the
 * figures, because handing that to the fee is a decision about the operator's money and belongs to the caller that
 * knows whose money it is.
 */
final class SingleChangeOutput implements ChangeStrategy
{
    /**
     * @return list<TransactionOutput>
     */
    public function change(ValueBag $surplus, string $changeAddress, LedgerParameters $parameters): array
    {
        if ($surplus->isEmpty()) {
            return [];
        }

        if (! $surplus->hasAssets()) {
            $minimum = MinimumUtxo::under($parameters)->forValue($changeAddress, Value::lovelace('0'));

            if ($surplus->coin->isLessThan($minimum)) {
                throw new SelectionException(sprintf(
                    'A change output at this address needs %s lovelace and the surplus is %s. Giving the difference '
                    .'to the fee is a decision about the operator\'s money, so it is not taken here.',
                    $minimum->value,
                    $surplus->coin->value
                ));
            }
        }

        return ValuePacker::under($parameters)->pack($surplus, $changeAddress);
    }

    /**
     * The smallest surplus this strategy can turn into change at a given address, when the surplus is lovelace only.
     *
     * Exposed so a caller can ask before it selects, rather than discovering it from an exception after it has.
     */
    public static function minimumLovelaceChange(string $changeAddress, LedgerParameters $parameters): Natural
    {
        return MinimumUtxo::under($parameters)->forValue($changeAddress, Value::lovelace('0'));
    }
}
