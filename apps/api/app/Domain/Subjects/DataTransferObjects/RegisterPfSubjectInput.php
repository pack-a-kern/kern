<?php

declare(strict_types=1);

namespace App\Domain\Subjects\DataTransferObjects;

/**
 * What a caller hands to `App\Domain\Subjects\Actions\RegisterPfSubject`. `birthDate` is a plain
 * `Y-m-d` string, not a `DateTimeInterface` — it is stored only as ciphertext
 * (`pf_profiles.birth_date_ciphertext`), never parsed or compared by this module (no fiscal logic
 * lives here, `docs/AGENTS.md` §6), so there is nothing for a richer type to buy here.
 */
final readonly class RegisterPfSubjectInput
{
    public function __construct(
        public string $registeringUserId,
        public string $displayName,
        public string $firstName,
        public string $lastName,
        public string $cnp,
        public string $birthDate,
        public ?string $countyCode = null,
        public ?string $locality = null,
        public ?string $address = null,
        public ?string $anafTaxOfficeCode = null,
    ) {
    }
}
