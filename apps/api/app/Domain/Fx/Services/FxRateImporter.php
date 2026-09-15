<?php

declare(strict_types=1);

namespace App\Domain\Fx\Services;

use App\Domain\Fx\DataTransferObjects\ParsedFxRate;
use App\Domain\Fx\Enums\FxRateImportOutcome;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Models\FxRateAnomaly;
use App\Domain\Fx\ValueObjects\DecimalRate;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Idempotent, never-overwriting upsert of one parsed rate into `ext_fx_rates` (`BLUEPRINT.md` §7.2 steps
 * 7-9). Three outcomes, never a fourth:
 *
 * - No existing row for `(fx_source, rate_date, currency)` → **create** it.
 * - An existing row with the identical `multiplier`/`rate_decimal` → **unchanged**, a true no-op
 *   (this is what makes re-running an import idempotent).
 * - An existing row with a *different* `multiplier` or `rate_decimal` → the existing row is left
 *   completely untouched and an `ext_fx_rate_anomalies` row is written instead — BLUEPRINT.md §7.2 step 9
 *   verbatim: "dacă valoarea ... se modifică, create anomaly, nu overwrite silent."
 */
final readonly class FxRateImporter
{
    public function import(
        string $officialSourceId,
        DateTimeImmutable $rateDate,
        ParsedFxRate $rate,
        string $sourceDocumentVersionId,
    ): FxRateImportOutcome {
        $rateDateString = $rateDate->format('Y-m-d');

        /** @var FxRate|null $existing */
        $existing = FxRate::query()
            ->where('official_source_id', $officialSourceId)
            ->where('rate_date', $rateDateString)
            ->where('currency_code', $rate->currencyCode)
            ->first();

        if ($existing === null) {
            FxRate::query()->create([
                'official_source_id' => $officialSourceId,
                'rate_date' => $rateDateString,
                'currency_code' => $rate->currencyCode,
                'multiplier' => $rate->multiplier,
                'rate_decimal' => $rate->rateDecimal->toString(),
                'source_document_version_id' => $sourceDocumentVersionId,
            ]);

            return FxRateImportOutcome::CREATED;
        }

        $existingRate = DecimalRate::fromString((string) $existing->rate_decimal);

        if ($existing->multiplier === $rate->multiplier && $existingRate->equals($rate->rateDecimal)) {
            return FxRateImportOutcome::UNCHANGED;
        }

        FxRateAnomaly::query()->create([
            'official_source_id' => $officialSourceId,
            'rate_date' => $rateDateString,
            'currency_code' => $rate->currencyCode,
            'existing_multiplier' => $existing->multiplier,
            'existing_rate_decimal' => (string) $existing->rate_decimal,
            'incoming_multiplier' => $rate->multiplier,
            'incoming_rate_decimal' => $rate->rateDecimal->toString(),
            'incoming_source_document_version_id' => $sourceDocumentVersionId,
            'detected_at' => CarbonImmutable::now('UTC'),
        ]);

        return FxRateImportOutcome::ANOMALY;
    }
}
