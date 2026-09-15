<?php

declare(strict_types=1);

namespace App\Domain\Banks\Actions;

use App\Domain\Banks\DataTransferObjects\BankImportSummary;
use App\Domain\Banks\DataTransferObjects\EcbInstitution;
use App\Domain\Banks\Exceptions\BankSourceNotConfiguredException;
use App\Domain\Banks\Models\Bank;
use App\Domain\Banks\Parsing\BicLeiMappingParser;
use App\Domain\Banks\Parsing\EcbInstitutionListParser;
use App\Domain\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use App\Domain\Banks\Parsing\LegalFormListParser;
use App\Domain\Banks\Parsing\SourceDownloadLinks;
use App\Domain\Banks\Services\BankNameBuilder;
use App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher;
use App\Domain\TaxKnowledge\Services\SourceIngestionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
 * Each is stored through {@see SourceIngestionService}, like BNR's rate files: content-addressed,
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
    private const string ECB = 'ECB';

    private const string GLEIF = 'GLEIF';

    private const string INSTITUTION_LIST = 'BANK_INSTITUTION_LIST';

    private const string BIC_LEI_MAPPING = 'BIC_LEI_MAPPING';

    private const string LEGAL_FORMS = 'LEGAL_FORM_LIST';

    /** A BIC ending in this is a bank's primary office, as opposed to one of its branch codes. */
    private const string PRIMARY_OFFICE_SUFFIX = 'XXX';

    public function __construct(
        private ConfigRepository $config,
        private AllowlistedHttpFetcher $fetcher,
        private SourceIngestionService $ingestion,
        private SourceDownloadLinks $links,
        private EcbInstitutionListParser $institutionParser,
        private BicLeiMappingParser $bicParser,
        private LegalFormListParser $legalFormParser,
        private BankNameBuilder $names,
    ) {
    }

    public function __invoke(): BankImportSummary
    {
        $countries = $this->filter('countries');
        $categories = $this->filter('categories');

        $listLink = $this->links->ecbInstitutionList(...$this->page(self::ECB, 'institution_list_page'));
        $bicLink = $this->links->gleifBicLeiMapping(...$this->page(self::GLEIF, 'bic_lei_page'));
        $legalFormLink = $this->links->gleifLegalForms(...$this->page(self::GLEIF, 'legal_forms_page'));

        $institutions = array_values(array_filter(
            $this->institutionParser->parse($this->ingestion->ingest(self::ECB, $listLink->url, self::INSTITUTION_LIST)->rawBytes),
            static fn (EcbInstitution $institution): bool => in_array($institution->countryCode, $countries, true)
                && in_array($institution->category, $categories, true),
        ));

        if ($institutions === []) {
            throw InvalidBankSourceFileException::noInstitutions($countries);
        }

        $bics = $this->bicParser->parse($this->ingestion->ingest(self::GLEIF, $bicLink->url, self::BIC_LEI_MAPPING)->rawBytes);
        $legalForms = $this->legalFormParser->parse($this->ingestion->ingest(self::GLEIF, $legalFormLink->url, self::LEGAL_FORMS)->rawBytes);

        $listDate = $listLink->publishedOn->format('Y-m-d');
        $bicListDate = $bicLink->publishedOn->format('Y-m-d');

        return DB::transaction(function () use ($institutions, $countries, $bics, $legalForms, $listDate, $bicListDate): BankImportSummary {
            $created = 0;
            $withoutBic = 0;

            foreach ($institutions as $institution) {
                $bic = $this->bicFor($institution, $bics);
                $withoutBic += $bic === null ? 1 : 0;

                $attributes = [
                    'lei' => self::orNull($institution->lei),
                    'bic' => $bic,
                    'name' => $this->names->build($institution, $legalForms),
                    'legal_name' => $institution->name,
                    'parent_name' => self::orNull($institution->parentName),
                    'parent_lei' => self::orNull($institution->parentLei),
                    'category' => $institution->category,
                    'country_code' => $institution->countryCode,
                    'city' => self::orNull($institution->city),
                    'postal_code' => self::orNull($institution->postalCode),
                    'address' => self::orNull($institution->address),
                    'active' => true,
                    'last_seen_on' => $listDate,
                    'bic_checked_on' => $bicListDate,
                ];

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
                bicListDate: $bicListDate,
            );
        });
    }

    /**
     * The bank's BIC in its own country.
     *
     * Looked up by the bank's own LEI, then by its parent's: a branch has no LEI, but GLEIF lists its
     * Romanian BIC under the bank it belongs to — ING's `INGBROBU` sits under ING Bank N.V. — beside
     * that bank's codes for every other country, which is why the country is checked. Among several,
     * the primary office's code (`…XXX`) wins over a department's.
     *
     * @param  array<string, list<string>>  $bics
     */
    private function bicFor(EcbInstitution $institution, array $bics): ?string
    {
        foreach ([$institution->lei, $institution->parentLei] as $lei) {
            $inCountry = array_values(array_filter(
                $lei === '' ? [] : $bics[strtoupper($lei)] ?? [],
                static fn (string $bic): bool => substr($bic, 4, 2) === $institution->countryCode,
            ));

            if ($inCountry === []) {
                continue;
            }

            sort($inCountry);

            foreach ($inCountry as $bic) {
                if (str_ends_with($bic, self::PRIMARY_OFFICE_SUFFIX)) {
                    return $bic;
                }
            }

            return $inCountry[0];
        }

        return null;
    }

    /**
     * The download page for one source, fetched through the allowlist.
     *
     * @return array{0: string, 1: string} the page's HTML and its address
     */
    private function page(string $source, string $endpoint): array
    {
        $url = $this->config->get("sources.entries.{$source}.endpoints.{$endpoint}");

        if (! is_string($url) || $url === '') {
            throw BankSourceNotConfiguredException::endpoint($source, $endpoint);
        }

        return [$this->fetcher->fetch($source, $url)->body, $url];
    }

    /**
     * @return list<string>
     */
    private function filter(string $key): array
    {
        $values = $this->config->get("banks.{$key}");
        $values = is_array($values) ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')) : [];

        if ($values === []) {
            throw BankSourceNotConfiguredException::filter($key);
        }

        return $values;
    }

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
