<?php

declare(strict_types=1);

namespace App\Domain\Banks\Actions;

use App\Domain\Banks\DataTransferObjects\BankImportSummary;
use App\Domain\Banks\DataTransferObjects\EcbInstitution;
use App\Domain\Banks\Models\Bank;
use App\Domain\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use App\Domain\Banks\Services\BankSources;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes `ext_banks` from the ECB's list of institutions, with BICs from GLEIF.
 *
 * ## Order: everything read before anything written
 *
 * Three files are downloaded — the ECB list, GLEIF's BIC-to-LEI file, and the ISO 20275 legal forms —
 * and all three are parsed before the table is touched. A page that changed shape or a truncated
 * archive stops the import with the table as it was. The one outcome this must never have is a short
 * list quietly marking every missing bank inactive.
 *
 * ## Every file kept as evidence
 *
 * Each is stored through `SourceIngestionService`, like BNR's rate files: content-addressed,
 * versioned, and linked to its source in `ext_official_sources`. Downloading them also puts ECB and
 * GLEIF into that table the first time, which is what turns them from "never fetched" to "in use" on
 * the settings screen.
 *
 * ## What an update does
 *
 * - A bank in the list is created, or refreshed in place, matched by its ECB code.
 * - A bank no longer in the list, in a configured country, becomes inactive. It is never deleted: a
 *   deposit may name it.
 * - A bank in a country no longer configured is left alone, active or not, for the same reason.
 */
final readonly class ImportBanks
{
    public function __construct(private BankSources $sources)
    {
    }

    public function __invoke(): BankImportSummary
    {
        $countries = $this->sources->filter('countries');
        $listLink = $this->sources->currentList();
        $institutions = $this->sources->institutions($listLink->url);

        if ($institutions === []) {
            throw InvalidBankSourceFileException::noInstitutions($countries);
        }

        $files = $this->sources->referenceFiles();
        $listDate = $listLink->publishedOn->format('Y-m-d');

        return DB::transaction(function () use ($institutions, $countries, $files, $listDate): BankImportSummary {
            $created = 0;
            $withoutBic = 0;

            foreach ($institutions as $institution) {
                $attributes = [...$this->sources->attributes($institution, $files), 'active' => true, 'last_seen_on' => $listDate];
                $withoutBic += $attributes['bic'] === null ? 1 : 0;

                $bank = Bank::query()->where('ecb_code', $institution->ecbCode)->first();

                if ($bank === null) {
                    Bank::query()->create(['ecb_code' => $institution->ecbCode, 'first_seen_on' => $listDate, ...$attributes]);
                    $created++;
                } else {
                    $bank->fill($attributes)->save();
                }
            }

            $deactivated = Bank::query()
                ->whereIn('country_code', $countries)
                ->where('active', true)
                ->whereNotIn('ecb_code', array_map(static fn (EcbInstitution $institution): string => $institution->ecbCode, $institutions))
                ->update(['active' => false, 'updated_at' => now('UTC')]);

            return new BankImportSummary(
                listed: count($institutions),
                created: $created,
                deactivated: $deactivated,
                withoutBic: $withoutBic,
                listDate: $listDate,
                bicListDate: $files->bicListDate,
            );
        });
    }
}
