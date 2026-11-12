<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Queries;

use Kern\TaxRomania\Subjects\Enums\SubjectType;

/** One taxpayer as the switcher draws it: its name, its kind and its picture — nothing else. */
final readonly class SwitcherSubject
{
    public function __construct(
        public string $id,
        public SubjectType $type,
        public string $displayName,
        /** The cache key of the picture's URL, or null for a taxpayer showing its initials. */
        public ?string $avatarUpdatedAt,
    ) {
    }

    /** @return array{id: string, type: string, display_name: string, avatar_updated_at: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'display_name' => $this->displayName,
            'avatar_updated_at' => $this->avatarUpdatedAt,
        ];
    }
}
