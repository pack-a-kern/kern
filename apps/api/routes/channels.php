<?php

declare(strict_types=1);

use App\Domain\Subjects\Models\SubjectMembership;
use Illuminate\Support\Facades\Broadcast;

/*
 * `App\Domain\Documents\Events\*` broadcast on `tax-subjects.{subjectId}.documents` (`BLUEPRINT.md`
 * §16). Authorization mirrors `App\Domain\Subjects\Tenancy\TenantContext::authorizeAndActivate()`
 * exactly — an active, non-revoked `SubjectMembership` — queried directly rather than through
 * `TenantContext` because a broadcasting-auth request has no bound tenant context to activate; it
 * is answering "may this user listen," not "act as this subject."
 */
Broadcast::channel('tax-subjects.{subjectId}.documents', function ($user, string $subjectId): bool {
    return SubjectMembership::query()
        ->where('subject_id', $subjectId)
        ->where('user_id', $user->id)
        ->whereNull('revoked_at')
        ->exists();
});

/**
 * `App\Domain\Spv\Events\*` broadcast on `tax-subjects.{subjectId}.spv` (`BLUEPRINT.md` §16) — same
 * authorization rule as the documents channel above.
 */
Broadcast::channel('tax-subjects.{subjectId}.spv', function ($user, string $subjectId): bool {
    return SubjectMembership::query()
        ->where('subject_id', $subjectId)
        ->where('user_id', $user->id)
        ->whereNull('revoked_at')
        ->exists();
});
