<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exceptions;

use DateTimeImmutable;
use RuntimeException;

/**
 * Zero `tax_rule_versions` rows (in a usable `Kern\Platform\Approval\ApprovalStatus` —
 * `CANDIDATE` or `APPROVED`, never `REJECTED`/`SUPERSEDED`) cover the requested date. Never
 * resolved by falling back to the nearest version — `docs/AGENTS.md` §2 forbids guessing, exactly
 * the same principle `Kern\Legislation\Services\LegislationTemporalQuery` already applies
 * to legal text.
 */
final class NoTaxRuleVersionInForceException extends RuntimeException
{
    public static function forDate(string $ruleCode, DateTimeImmutable $date): self
    {
        return new self(sprintf('No tax rule version for "%s" is in force on %s.', $ruleCode, $date->format('Y-m-d')));
    }
}
