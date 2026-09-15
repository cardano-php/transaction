<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use RuntimeException;

/**
 * A protocol parameter the arithmetic reads is missing, unreadable, or outside the range it has to be in.
 *
 * There is no default for any of them. A guessed fee parameter builds a transaction the node refuses; a guessed
 * utxoCostPerByte overfunds or underfunds every output a campaign ever pays out.
 */
final class ParameterException extends RuntimeException {}
