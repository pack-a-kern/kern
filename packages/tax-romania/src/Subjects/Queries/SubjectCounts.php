<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * How many taxpayers one person works in now, and how many of those let an external AI read their
 * documents — counted here, over all of them, so a screen showing the two numbers downloads neither
 * list.
 */
final readonly class SubjectCounts
{
    public function __construct(private DocumentAiConsentGate $consent)
    {
    }

    /** @return array{active: int, ai_consented: int} */
    public function __invoke(string $userId): array
    {
        $active = TaxSubject::query()
            ->whereIn('id', SubjectMembership::query()->where('user_id', $userId)->opening()->select('subject_id'))
            ->where('status', SubjectStatus::ACTIVE);

        return [
            'active' => (clone $active)->count(),
            'ai_consented' => $active->whereIn('id', $this->consent->consentedSubjectIds())->count(),
        ];
    }
}
