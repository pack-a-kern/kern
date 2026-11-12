<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * The taxpayers one person can work in now: every one their memberships open, in use — the whole list,
 * not a page. `SUBJECTS_MAX_ACTIVE` keeps it short (`SubjectAllowance`); a closed one is managed on the
 * taxpayers screen, not chosen here.
 *
 * Only the columns the switcher draws are read, and only the name is decrypted.
 */
final readonly class ActiveSubjectsForUser
{
    /** @return list<SwitcherSubject> oldest first */
    public function __invoke(string $userId): array
    {
        return array_values(TaxSubject::query()
            ->whereIn('id', SubjectMembership::query()->where('user_id', $userId)->opening()->select('subject_id'))
            ->where('status', SubjectStatus::ACTIVE)
            ->orderBy('id')
            ->get(['id', 'type', 'display_name_ciphertext', 'avatar_updated_at'])
            ->map(static fn (TaxSubject $subject): SwitcherSubject => new SwitcherSubject(
                id: $subject->id,
                type: $subject->type,
                displayName: $subject->display_name_ciphertext ?? '',
                avatarUpdatedAt: $subject->avatarUpdatedAt(),
            ))
            ->all());
    }

    /**
     * What changes whenever that list could: a membership added or ended, a taxpayer renamed, closed,
     * reopened or given a picture. Read without decrypting anything, so the list itself is read again
     * only when this moves.
     */
    public function version(string $userId): string
    {
        $row = DB::table('subject_memberships as m')
            ->join('tax_subjects as s', 's.id', '=', 'm.subject_id')
            ->where('m.user_id', $userId)
            ->selectRaw('count(*) as memberships, max(m.updated_at) as memberships_at, max(s.updated_at) as subjects_at')
            ->first();

        return hash('xxh128', json_encode((array) $row, JSON_THROW_ON_ERROR));
    }
}
