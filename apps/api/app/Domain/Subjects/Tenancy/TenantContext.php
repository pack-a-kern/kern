<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Tenancy;

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SecurityEvent;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Tenancy\Exceptions\NoTenantContextException;
use App\Domain\Subjects\Tenancy\Exceptions\UnauthorizedSubjectAccessException;

/**
 * The single, sanctioned entry point for binding "which `TaxSubject` is this request/job acting
 * on behalf of, and as which user." Every other module scopes a query to a subject by putting
 * `App\Domain\Subjects\Tenancy\ScopedToTaxSubject` on its model and relying on this class having
 * already been activated — see `docs/handoff/011-identity-tenancy.md` "How another module scopes
 * a query to a subject correctly" for the full worked example.
 *
 * There is deliberately **no** lower-level "just set the subject id, trust me" method. The only
 * way to bind a subject is `authorizeAndActivate()`, which always re-checks membership against
 * the database — this is what makes an *unauthorized* cross-tenant read structurally hard to
 * write: there is no shortcut that skips the membership check, only the (separately named,
 * greppable) `Model::withoutGlobalScope(TaxSubjectScope::class)` escape for genuinely
 * subject-agnostic system queries (duplicate-CNP detection, admin tooling) that `App\Domain\
 * Subjects\Actions\RegisterPfSubject` demonstrates.
 *
 * **Must be bound as a scoped (per-request/per-job) container instance, never a process-lifetime
 * singleton** — exactly the same requirement `App\Support\KeyManagement\SubjectDekService`
 * documents for itself, and for the same reason: this object's mutable state (which subject is
 * currently active) must not leak from one HTTP request or queue job into the next. See
 * `App\Domain\Subjects\SubjectsServiceProvider`.
 */
final class TenantContext
{
    private ?string $subjectId = null;

    private ?MembershipRole $role = null;

    /**
     * Look up whether `$userId` holds a non-revoked `SubjectMembership` for `$subjectId`, and if
     * so, bind this context to it. Throws and records a `security_events` row otherwise —
     * including when a membership exists but was revoked, which must fail identically to "never
     * existed" (a revoked membership is not a softer form of access).
     */
    public function authorizeAndActivate(string $userId, string $subjectId): void
    {
        $membership = SubjectMembership::query()
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->first();

        if ($membership === null) {
            SecurityEvent::create([
                'event_type' => 'CROSS_TENANT_ACCESS_DENIED',
                'user_id' => $userId,
                'subject_id' => $subjectId,
                'context_json' => ['reason' => 'no_active_membership'],
                'occurred_at' => now('UTC'),
            ]);

            throw UnauthorizedSubjectAccessException::forUser($userId, $subjectId);
        }

        $this->subjectId = $subjectId;
        $this->role = $membership->role;
    }

    public function hasActiveSubject(): bool
    {
        return $this->subjectId !== null;
    }

    public function currentSubjectId(): string
    {
        return $this->subjectId ?? throw NoTenantContextException::notBound();
    }

    public function currentRole(): MembershipRole
    {
        return $this->role ?? throw NoTenantContextException::notBound();
    }
}
