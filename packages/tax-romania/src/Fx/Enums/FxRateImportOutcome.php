<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Enums;

/**
 * What {@see \Kern\TaxRomania\Fx\Services\FxRateImporter::import()} did with one parsed rate.
 * `ANOMALY` means the existing `ext_fx_rates` row was left untouched and an `ext_fx_rate_anomalies` row
 * was written instead (`BLUEPRINT.md` §7.2 step 9 — never a silent overwrite).
 */
enum FxRateImportOutcome: string
{
    case CREATED = 'CREATED';
    case UNCHANGED = 'UNCHANGED';
    case ANOMALY = 'ANOMALY';
}
