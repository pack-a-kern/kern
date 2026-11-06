<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Fx\DataTransferObjects\ParsedFxDay;
use Kern\TaxRomania\Fx\DataTransferObjects\ParsedFxRate;
use Kern\TaxRomania\Fx\Enums\FxRateImportOutcome;
use Kern\TaxRomania\Fx\Models\FxRate;
use Kern\TaxRomania\Fx\Models\FxRateAnomaly;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

/**
 * Idempotent, never-overwriting upsert of each parsed rate into `ext_fx_rates` (`BLUEPRINT.md` §7.2 steps
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
    /**
     * One day's rates, in one query and one transaction — a year's backfill is some 250 of these
     * rather than a lookup and a commit for each of its 9,000 rates.
     *
     * What is held in memory is that one day's existing rows (a few dozen), never the year's: a day is
     * the unit the feed publishes, so the memory stays the same however long the archive is.
     *
     * @return list<FxRateImportOutcome> one per rate, in the feed's order
     */
    public function import(string $officialSourceId, ParsedFxDay $day, string $sourceDocumentVersionId): array
    {
        $rateDateString = $day->date->format('Y-m-d');

        return DB::transaction(function () use ($officialSourceId, $day, $rateDateString, $sourceDocumentVersionId): array {
            /** @var array<string, FxRate> $existing by currency */
            $existing = FxRate::query()
                ->where('official_source_id', $officialSourceId)
                ->where('rate_date', $rateDateString)
                ->get()
                ->keyBy('currency_code')
                ->all();

            $outcomes = [];

            foreach ($day->rates as $rate) {
                // A currency the feed lists twice meets the row its first listing created, as it always has.
                $outcomes[] = isset($existing[$rate->currencyCode])
                    ? $this->compare($existing[$rate->currencyCode], $officialSourceId, $rateDateString, $rate, $sourceDocumentVersionId)
                    : FxRateImportOutcome::CREATED;

                $existing[$rate->currencyCode] ??= FxRate::query()->create([
                    'official_source_id' => $officialSourceId,
                    'rate_date' => $rateDateString,
                    'currency_code' => $rate->currencyCode,
                    'multiplier' => $rate->multiplier,
                    'rate_decimal' => $rate->rateDecimal->toString(),
                    'source_document_version_id' => $sourceDocumentVersionId,
                ]);
            }

            return $outcomes;
        });
    }

    private function compare(FxRate $existing, string $officialSourceId, string $rateDateString, ParsedFxRate $rate, string $sourceDocumentVersionId): FxRateImportOutcome
    {
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
