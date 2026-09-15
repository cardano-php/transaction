<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use InvalidArgumentException;

/**
 * Raised whenever a native script cannot be built or read.
 *
 * A script hash is the address that holds the funds, so the same rule applies as for addresses: an input that is not
 * exactly what it should be is refused, never coerced.
 */
final class ScriptException extends InvalidArgumentException {}
