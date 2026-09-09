<?php

declare(strict_types=1);

namespace App\Domain\Fx\Exceptions;

use RuntimeException;

/**
 * `App\Domain\Fx\Services\FxRateQuery` never interpolates, forward-fills, or falls back to an
 * adjacent day when an exact `(source, date, currency)` rate is missing — BLUEPRINT.md §7.5
 * ("Reproductibilitate") requires every conversion to record the exact rate/date/source used, and
 * silently substituting a different day's rate would violate that.
 */
final class FxRateNotFoundException extends RuntimeException
{
    public static function forQuery(string $sourceCode, string $currencyCode, string $date): self
    {
        return new self(sprintf('No %s rate for %s on %s.', $sourceCode, $currencyCode, $date));
    }
}
