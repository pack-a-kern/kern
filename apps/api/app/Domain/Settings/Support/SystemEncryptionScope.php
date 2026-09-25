<?php

declare(strict_types=1);

namespace App\Domain\Settings\Support;

/**
 * The encryption principal id for system-wide secrets that have no `TaxSubject` at all — today,
 * the AI provider API key (`docs/handoff/041-settings-module.md`). `Kern\Platform\Crypto\
 * AssociatedData` requires a `subject_id` segment for every field it protects; this is
 * deliberately **not** a real `TaxSubject`/`User` id pretending to be one (`docs/AGENTS.md` rule
 * 1's spirit applied here: do not invent a fake subject id). `self::ID` is a short, non-UUID
 * literal, so it can never collide with a genuine UUIDv7 subject/user id and is immediately
 * recognisable in a raw database dump as "not a tenant" — mirroring the identical pattern
 * `Kern\Audit\Services\AuditChainService::SYSTEM_CHAIN_KEY` already establishes in this
 * codebase for the audit chain, for the same reason.
 *
 * This scope is backed by its **own** DEK (`system_encryption_keys` table,
 * `App\Domain\Settings\Infrastructure\SystemDekRepository`) — never a `TaxSubject`'s or `User`'s
 * DEK. A ciphertext sealed under this scope therefore fails to decrypt under any tenant's key
 * (wrong key material) even before the AAD is ever checked, and a tenant's ciphertext fails to
 * decrypt under this key for the same reason, in addition to the AAD's leading segment differing.
 * See `tests/Unit/Domain/Settings/SystemEncryptionScopeIsolationTest.php` for both directions,
 * proven against the real `Kern\Platform\Crypto\EnvelopeCipher`.
 */
final class SystemEncryptionScope
{
    public const string ID = 'system';

    private function __construct()
    {
    }
}
