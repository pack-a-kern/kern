<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\DataTransferObjects;

/**
 * What a caller hands to `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject`. `birthDate` is a plain
 * `Y-m-d` string, not a `DateTimeInterface` — it is stored only as ciphertext
 * (`pf_profiles.birth_date_ciphertext`), never parsed or compared by this module (no fiscal logic
 * lives here, `docs/AGENTS.md` §6), so there is nothing for a richer type to buy here.
 */
final readonly class RegisterPfSubjectInput
{
    /**
     * @param  list<string>  $registeringUserRoles  the platform roles the account carries, which
     *                                              decide how many taxpayers it may own. Passed in
     *                                              rather than looked up: this module does not
     *                                              reach into Identity, and the caller already
     *                                              holds the authenticated user.
     */
    public function __construct(
        public string $registeringUserId,
        public array $registeringUserRoles,
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
