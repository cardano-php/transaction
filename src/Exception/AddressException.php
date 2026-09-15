<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use InvalidArgumentException;

/**
 * Raised whenever a credential, a pointer or an address cannot be built or read.
 *
 * An address has no migration. Once funds have been sent to one, an address derived from slightly wrong inputs is not
 * a mistake that can be corrected in place: the money sits behind whatever was actually encoded, reachable only by
 * whoever can satisfy it. Every doubtful input is therefore refused here rather than padded, trimmed, lowercased or
 * rounded into something plausible.
 */
final class AddressException extends InvalidArgumentException {}
