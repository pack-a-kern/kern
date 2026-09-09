<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Subjects\Actions\RegisterPfaSubject` when the registration number being
 * registered already matches another `TaxSubject`'s `fiscal_identifier_blind_index` (ADR 0011,
 * `docs/adr/0011-canonical-fiscal-identifier.md`) — the PFA counterpart of `DuplicateCnpException`
 * (`BLUEPRINT.md` §4.1's "must not register twice" invariant, generalised beyond PF). Binding
 * `App\Domain\Subjects\Enums\FiscalIdentifierKind::CUI` into the hashed value is what keeps this
 * check from ever matching an unrelated PF's CNP that normalises to the same digits. The message
 * never names the existing subject, for the same reason `DuplicateCnpException` does not.
 */
final class DuplicateRegistrationNumberException extends RuntimeException
{
    public static function alreadyRegistered(): self
    {
        return new self('A PFA profile with this registration number is already registered.');
    }
}
