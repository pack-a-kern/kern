<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Illuminate\Pagination\LengthAwarePaginator;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;

/**
 * "Which `TaxSubject`s can this `User` currently access, and under what role" — the read model
 * `docs/handoff/030-pwa-frontend.md`'s dashboard subject picker needs and, per that handoff's Key
 * decision #1, had no backend endpoint for. Driven entirely by `subject_memberships`, through the same
 * `opening()` rule `MembershipTenantGate` applies: a membership that would not open its taxpayer —
 * revoked, or in a role without rules yet — never appears.
 *
 * Deliberately reads `SubjectMembership`/`TaxSubject` directly rather than through
 * `TenantContext` — this query answers "which subjects could I activate," a question that must be
 * answerable *before* any one of them is bound, not "act as an already-chosen one." Both models
 * live in this same module, so this is an ordinary intra-module read, not a cross-module crossing
 * `deptrac.php` needs to know about.
 *
 * Numbered pages rather than a cursor, matching the documents list: an account holding several
 * taxpayers wants to know how many there are and to jump to the last one, and a cursor can answer
 * neither question. The cost is a `count()` per request, which on a table keyed by one user's
 * memberships is not a cost.
 */
final class ListSubjectsForUser
{
    private const int DEFAULT_PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, SubjectSummary>
     */
    public function __invoke(string $userId, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $memberships = SubjectMembership::query()
            ->where('user_id', $userId)
            ->opening()
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $subjectsById = TaxSubject::query()
            ->whereIn('id', $memberships->getCollection()->pluck('subject_id'))
            ->get()
            ->keyBy('id');

        return $memberships->through(static function (SubjectMembership $membership) use ($subjectsById): SubjectSummary {
            $subject = $subjectsById->get($membership->subject_id);

            if (! $subject instanceof TaxSubject) {
                // subject_memberships.subject_id is a foreign key to tax_subjects with no
                // ON DELETE that removes the subject while leaving the membership behind
                // (Kern\TaxRomania\Subjects\Actions\RegisterPfSubject never deletes a TaxSubject row;
                // BLUEPRINT.md §25 deletion is crypto-shredding + a status change, never a row
                // delete) — reaching this means the data violates that invariant, not a normal
                // "not found" a caller should silently tolerate.
                throw new RuntimeException(sprintf(
                    'Membership "%s" references tax subject "%s", which no longer exists.',
                    $membership->id,
                    $membership->subject_id,
                ));
            }

            return new SubjectSummary(
                id: $subject->id,
                type: $subject->type,
                displayName: $subject->display_name_ciphertext ?? '',
                status: $subject->status,
                role: $membership->role,
                // Stored in clear, so no decrypt: it is a preference, not personal data.
                displayCurrency: $subject->displayCurrency(),
                // Same: a timestamp, and the cache key for the picture's URL.
                avatarUpdatedAt: $subject->avatarUpdatedAt(),
            );
        });
    }
}
