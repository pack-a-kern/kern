<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Requirements;

use Illuminate\Contracts\Database\Query\Builder;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\FiscalParameters\ParameterCatalog;
use Kern\TaxRomania\TaxEngine\Services\FiscalYears;

/**
 * Whether this package's fiscal values are ready for the year being worked (ADR 0027), resolved
 * here — in the package that owns them — so `kern/legislation` decides `parameters` without ever
 * learning what a fiscal value is, the way a law's holdings are counted through
 * {@see \Kern\Legislation\Dependents\DocumentDependents}.
 *
 * "This year's values approved" is the minimum wage approved for the active year: it is the value
 * every threshold is a multiple of, so nothing a tax is worked out from can be computed until it is
 * in force. The other rules gate their own features, not the whole install.
 */
final readonly class FiscalParameterReadiness
{
    public function __construct(
        private ParameterCatalog $catalog,
        private FiscalYears $years,
    ) {
    }

    public function minimumWageApprovedForActiveYear(): bool
    {
        $parameterId = FiscalParameter::query()
            ->where('code', $this->catalog->minimumWage()->code)
            ->value('id');

        if (! is_string($parameterId)) {
            return false;
        }

        $year = $this->years->current();
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);

        return FiscalParameterVersion::query()
            ->where('fiscal_parameter_id', $parameterId)
            ->where('status', ApprovalStatus::APPROVED->value)
            ->whereDate('valid_from', '<=', $yearEnd)
            ->where(static fn (Builder $version): Builder => $version
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', $yearStart))
            ->exists();
    }
}
