<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use RuntimeException;

/**
 * Raised whenever a byte string is not a transaction this decoder is willing to read.
 *
 * Every refusal is one of these, whether it came from the CBOR layer or from the ledger shape on top of it, so a
 * caller has a single thing to catch and a negative fixture has a single thing to assert.
 */
final class DecodeException extends RuntimeException {}
