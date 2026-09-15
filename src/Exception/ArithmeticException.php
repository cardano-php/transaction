<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Exception;

use RuntimeException;

/**
 * A number left the range something can hold, or a subtraction went below zero.
 *
 * Every one of these is raised deliberately. Ledger arithmetic has no safe wrong answer: a fee computed low is a
 * transaction the node refuses, a fee computed high is the operator's own money, and a token quantity truncated to
 * fit a PHP integer is a balance that stops adding up.
 */
final class ArithmeticException extends RuntimeException {}
