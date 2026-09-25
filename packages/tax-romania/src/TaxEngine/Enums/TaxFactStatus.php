<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Enums;

/**
 * `tax_facts.status` (`BLUEPRINT.md` §6.7, §14.6). A fact an AI extraction pipeline proposes is
 * always `PROPOSED`; only a human-reviewed `CONFIRMED` fact may feed a `FINAL`
 * `Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus` assessment (`ADR 0004` decision 6). `IGNORED`
 * marks a fact a human explicitly excluded (e.g. a duplicate or misclassified import) without
 * deleting the row — deletion would destroy the audit trail of what was considered.
 */
enum TaxFactStatus: string
{
    case PROPOSED = 'PROPOSED';
    case CONFIRMED = 'CONFIRMED';
    case IGNORED = 'IGNORED';
}
