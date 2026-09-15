<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use InvalidArgumentException;

/**
 * Raised whenever a wall clock instant cannot be turned into a slot, or a slot into an instant.
 *
 * These conversions feed a script's time locks, and a time lock is hashed into an address. A conversion that rounded
 * rather than refused would move the address by exactly as much as it rounded.
 */
final class TimeException extends InvalidArgumentException {}
