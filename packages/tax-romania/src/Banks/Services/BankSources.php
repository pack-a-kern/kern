<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Services\SourceIngestionService;
use Kern\TaxRomania\Banks\DataTransferObjects\BankReferenceFiles;
use Kern\TaxRomania\Banks\DataTransferObjects\DownloadLink;
use Kern\TaxRomania\Banks\DataTransferObjects\EcbInstitution;
use Kern\TaxRomania\Banks\Exceptions\BankSourceNotConfiguredException;
use Kern\TaxRomania\Banks\Parsing\BicLeiMappingParser;
use Kern\TaxRomania\Banks\Parsing\EcbInstitutionListParser;
use Kern\TaxRomania\Banks\Parsing\LegalFormListParser;
use Kern\TaxRomania\Banks\Parsing\SourceDownloadLinks;

/**
 * The files a bank list is built from, downloaded, stored as evidence and parsed.
 *
 * Shared by the daily import, which reads today's ECB list, and the backfill, which reads the ones
 * the ECB archived — so a bank reads the same, whichever list it came from.
 */
final readonly class BankSources
{
    public const string ECB = 'ECB';

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

    /** Today's ECB list, as linked from its page. */
    public function currentList(): DownloadLink
    {
        return $this->links->ecbInstitutionList(...$this->page(self::ECB, 'institution_list_page'));
    }

    /**
     * The credit institutions of the configured countries in one ECB list.
     *
     * @return list<EcbInstitution>
     */
    public function institutions(string $url): array
    {
        $countries = $this->filter('countries');
        $categories = $this->filter('categories');

        return array_values(array_filter(
            $this->institutionParser->parse($this->ingestion->ingest(self::ECB, $url, self::INSTITUTION_LIST)->rawBytes),
            static fn (EcbInstitution $institution): bool => in_array($institution->countryCode, $countries, true)
                && in_array($institution->category, $categories, true),
        ));
    }

    public function referenceFiles(): BankReferenceFiles
    {
        $bicLink = $this->links->gleifBicLeiMapping(...$this->page(self::GLEIF, 'bic_lei_page'));
        $legalFormLink = $this->links->gleifLegalForms(...$this->page(self::GLEIF, 'legal_forms_page'));

        return new BankReferenceFiles(
            bics: $this->bicParser->parse($this->ingestion->ingest(self::GLEIF, $bicLink->url, self::BIC_LEI_MAPPING)->rawBytes),
            legalForms: $this->legalFormParser->parse($this->ingestion->ingest(self::GLEIF, $legalFormLink->url, self::LEGAL_FORMS)->rawBytes),
            bicListDate: $bicLink->publishedOn->format('Y-m-d'),
        );
    }

    /**
     * A bank's row as one list describes it — everything but whether it is active and when it was seen.
     *
     * @return array<string, string|null>
     */
    public function attributes(EcbInstitution $institution, BankReferenceFiles $files): array
    {
        return [
            'lei' => self::orNull($institution->lei),
            'bic' => $this->bicFor($institution, $files->bics),
            'name' => $this->names->build($institution, $files->legalForms),
            'legal_name' => $institution->name,
            'parent_name' => self::orNull($institution->parentName),
            'parent_lei' => self::orNull($institution->parentLei),
            'category' => $institution->category,
            'country_code' => $institution->countryCode,
            'city' => self::orNull($institution->city),
            'postal_code' => self::orNull($institution->postalCode),
            'address' => self::orNull($institution->address),
            'bic_checked_on' => $files->bicListDate,
        ];
    }

    /** @return list<string> */
    public function filter(string $key): array
    {
        $values = $this->config->get("banks.{$key}");
        $values = is_array($values) ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '')) : [];

        if ($values === []) {
            throw BankSourceNotConfiguredException::filter($key);
        }

        return $values;
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

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
