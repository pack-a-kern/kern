<?php

declare(strict_types=1);

namespace App\Domain\Subjects\DataTransferObjects;

/**
 * What a caller hands to `App\Domain\Subjects\Actions\RegisterPfaSubject` — the PFA counterpart
 * of `RegisterPfSubjectInput` (`docs/handoff/034-pfa-extension.md`). `registrationNumber` is a
 * plain string, not a richer value object: this module has no verified format rule for it (no
 * fiscal logic lives here, `docs/AGENTS.md` §6) — it is stored only as ciphertext plus a blind
 * index for duplicate detection, never parsed or validated for shape.
 */
final readonly class RegisterPfaSubjectInput
{
    public function __construct(
        public string $registeringUserId,
        /** @var list<string> platform roles, which decide how many taxpayers the account may own. */
        public array $registeringUserRoles,
        public string $displayName,
        public string $tradeName,
        public string $registrationNumber,
        public ?string $caenCode = null,
        public ?string $countyCode = null,
        public ?string $locality = null,
        public ?string $address = null,
    ) {
    }
}
