<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use RuntimeException;

/**
 * Raised whenever a key, a signature or a witness is not what signing needs it to be.
 *
 * Every refusal in the signing path is one of these. It is deliberately not a DecodeException: reading a transaction
 * someone else wrote and producing one of our own fail for different reasons and a caller that catches one should
 * not silently swallow the other.
 */
final class SigningException extends RuntimeException {}
