<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Exceptions;

use DateTimeImmutable;
use RuntimeException;

/**
 * More than one `tax_rule_versions` row for the same `tax_rule_id` claims a range covering the
 * requested date — a data-integrity defect (overlapping `valid_from`/`valid_until`), never
 * something resolved by picking one (mirrors
 * `App\Domain\TaxKnowledge\Exceptions\AmbiguousEffectiveVersionException`).
 */
final class AmbiguousTaxRuleVersionException extends RuntimeException
{
    /**
     * @param list<string> $versionIds
     */
    public static function forDate(string $ruleCode, DateTimeImmutable $date, array $versionIds): self
    {
        return new self(sprintf(
            'Overlapping effective ranges for tax rule "%s" on %s: versions [%s]. Fix the data — never guess which applies.',
            $ruleCode,
            $date->format('Y-m-d'),
            implode(', ', $versionIds),
        ));
    }
}
