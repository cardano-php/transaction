<?php

// SPDX-FileCopyrightText: 2026 Adam Dean
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Cardano\Transaction\Address;

/**
 * What a credential hashes: an Ed25519 verification key, or a script.
 *
 * This is the bit of an address header that says how the funds behind it are unlocked. A key credential is satisfied
 * by a signature, a script credential by the script itself and whatever the script asks for.
 */
enum CredentialKind
{
    case Key;

    case Script;
}
