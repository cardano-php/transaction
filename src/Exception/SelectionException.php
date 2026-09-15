<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use RuntimeException;

/**
 * Coin selection could not reach the target, or could not reach it inside the limits it was given.
 *
 * The message names the dimension that fell short and by how much. "Insufficient funds" on a wallet holding plenty
 * of ADA and none of the asset being paid out is the least useful sentence the system could produce.
 */
final class SelectionException extends RuntimeException {}
