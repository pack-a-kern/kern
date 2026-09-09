<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Subjects\Actions\RegisterPfSubject` when the CNP being registered already
 * matches another `TaxSubject`'s `fiscal_identifier_blind_index` (ADR 0011,
 * `docs/adr/0011-canonical-fiscal-identifier.md`; `BLUEPRINT.md` §4.1: "a PF must not register twice
 * under different TaxSubject records"). The message never names the existing subject — doing so
 * would leak a cross-tenant correlation to whoever triggered the check, exactly the residual risk
 * `docs/adr/0002-envelope-encryption.md` documents about the global blind-index key.
 */
final class DuplicateCnpException extends RuntimeException
{
    public static function alreadyRegistered(): self
    {
        return new self('A PF profile with this CNP is already registered.');
    }
}
