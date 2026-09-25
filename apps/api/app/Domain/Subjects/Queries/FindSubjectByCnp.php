<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Queries;

use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;

/**
 * Is this CNP already registered, and if so, is it one of mine?
 *
 * Deliberately **not** tenant-scoped, for the same reason the duplicate check in
 * `RegisterPfSubject` is not: a CNP identifies one human being across the whole installation, and a
 * check that only looked inside the caller's own taxpayers would cheerfully let the same person be
 * registered twice by two accounts.
 *
 * ## What it may say, and what it must not
 *
 * "You already have this taxpayer" is the caller's own data and is safe to say plainly. "Someone
 * else has it" must stay exactly that vague: no name, no owner, no id. Anything more would turn
 * registration into a way of asking who else uses this installation.
 */
final readonly class FindSubjectByCnp
{
    public function __construct(private BlindIndexHasher $blindIndex)
    {
    }

    /** @return array{registered: bool, mine: bool, subjectId: string|null} */
    public function __invoke(string $cnp, string $userId): array
    {
        $index = $this->blindIndex->compute(FiscalIdentifierKind::CNP->keyedValue($cnp));

        /** @var TaxSubject|null $subject */
        $subject = TaxSubject::query()->where('fiscal_identifier_blind_index', $index)->first();

        if (! $subject instanceof TaxSubject) {
            return ['registered' => false, 'mine' => false, 'subjectId' => null];
        }

        $mine = SubjectMembership::query()
            ->where('subject_id', $subject->id)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();

        return [
            'registered' => true,
            'mine' => $mine,
            // Only ever handed back for a taxpayer the caller already holds — it is their own id.
            'subjectId' => $mine ? $subject->id : null,
        ];
    }

    /** True when the caller owns it, rather than merely having been given access. */
    public function ownedBy(string $subjectId, string $userId): bool
    {
        return SubjectMembership::query()
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
            ->where('role', MembershipRole::OWNER->value)
            ->whereNull('revoked_at')
            ->exists();
    }
}
