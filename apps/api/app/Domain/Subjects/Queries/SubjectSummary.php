<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Queries;

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Enums\SubjectStatus;
use App\Domain\Subjects\Enums\SubjectType;

/**
 * One row of {@see ListSubjectsForUser}'s result — a `TaxSubject` the requesting user can
 * currently access, together with the role that access is held under. `displayName` is already
 * decrypted (`App\Domain\Subjects\Models\TaxSubject::display_name_ciphertext`'s own
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
        /** The currency this subject's wallet totals are added up in — a display preference. */
        public string $displayCurrency,
    ) {
    }

    /**
     * @return array{id: string, type: string, display_name: string, status: string, role: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'display_name' => $this->displayName,
            'status' => $this->status->value,
            'role' => $this->role->value,
            'display_currency' => $this->displayCurrency,
        ];
    }
}
