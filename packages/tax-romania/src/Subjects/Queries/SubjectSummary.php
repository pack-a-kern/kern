<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;

/**
 * One row of {@see ListSubjectsForUser}'s result — a `TaxSubject` the requesting user can
 * currently access, together with the role that access is held under. `displayName` is already
 * decrypted (`Kern\TaxRomania\Subjects\Models\TaxSubject::display_name_ciphertext`'s own
 * `Kern\Platform\Crypto\Casts\Encrypted` cast did that on read) — never a ciphertext column.
 */
final readonly class SubjectSummary
{
    public function __construct(
        public string $id,
        public SubjectType $type,
        public string $displayName,
        public SubjectStatus $status,
        public MembershipRole $role,
        /** Whether its documents may be read by an external AI (`DocumentAiConsentGate`). */
        public bool $aiConsent,
        /** The currency this subject's wallet totals are added up in — a display preference. */
        public string $displayCurrency,
        /**
         * When the picture last changed, or null for a taxpayer showing its initials.
         *
         * The timestamp rather than a path: it is the cache key the image URL carries, and a path is
         * where a file sits on this installation's disk, which is nothing a browser should be told.
         */
        public ?string $avatarUpdatedAt = null,
    ) {
    }

    /**
     * @return array{id: string, type: string, display_name: string, status: string, role: string, ai_consent: bool, display_currency: string, avatar_updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'display_name' => $this->displayName,
            'status' => $this->status->value,
            'role' => $this->role->value,
            'ai_consent' => $this->aiConsent,
            'display_currency' => $this->displayCurrency,
            'avatar_updated_at' => $this->avatarUpdatedAt,
        ];
    }
}
