<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

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
 *
 * A CNP left behind by {@see \Kern\TaxRomania\Subjects\Actions\ResetTaxSubject} — a husk keeping
 * only the hash, with no membership in force — reads as **available**, the same answer as a CNP
 * never seen: registration will reconnect it ({@see \Kern\TaxRomania\Subjects\Actions\RegisterPfSubject}),
 * and reporting it as taken would both block the re-add and reveal that the CNP had once been used.
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

        // An unowned husk (reset: hash kept, every membership revoked) is addable, not taken — the
        // same answer as a CNP never registered, so it neither blocks the re-add nor reveals the past.
        if (! SubjectMembership::query()->where('subject_id', $subject->id)->active()->exists()) {
            return ['registered' => false, 'mine' => false, 'subjectId' => null];
        }

        $mine = SubjectMembership::opens($userId, $subject->id);

        return [
            'registered' => true,
            'mine' => $mine,
            // Only ever handed back for a taxpayer the caller already holds — it is their own id.
            'subjectId' => $mine ? $subject->id : null,
        ];
    }
}
