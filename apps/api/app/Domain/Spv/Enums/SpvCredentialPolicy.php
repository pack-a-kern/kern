<?php

declare(strict_types=1);

namespace App\Domain\Spv\Enums;

/**
 * `spv_connections.credential_policy` (`BLUEPRINT.md` §6.11). Exactly one case exists because
 * exactly one policy is authorised anywhere in this codebase: an SPV password is never persisted,
 * in any form, for any duration beyond a single in-flight request to `apps/spv-browser`
 * (`BLUEPRINT.md` §13.3, §34 — permanent storage "purely for convenience is explicitly out of
 * scope"). This column exists so that guarantee is a queryable, auditable fact about a
 * connection, not just an absence of a column a future migration could quietly add back —
 * matching `App\Domain\Identity\Enums\MfaMethodType`'s identical "one case today, schema allows
 * more later" precedent.
 */
enum SpvCredentialPolicy: string
{
    case EPHEMERAL_ONLY = 'EPHEMERAL_ONLY';
}
