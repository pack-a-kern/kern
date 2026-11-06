<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Services\SourceIngestionService;
use Kern\TaxRomania\Fx\DataTransferObjects\FxImportSummary;
use Kern\TaxRomania\Fx\Exceptions\BnrEndpointNotConfiguredException;
use Kern\TaxRomania\Fx\Parsing\BnrXmlParser;
use Kern\TaxRomania\Fx\Services\CurrencyCatalogue;
use Kern\TaxRomania\Fx\Services\FxRateImporter;

/**
 * `BLUEPRINT.md` §7.3 `BackfillBnrYear(year)` — same pipeline as
 * {@see ImportBnrDailyRates} against the yearly archive endpoint, importing every banking day the
 * archive contains. `BNR_YEARLY_XML_URL_TEMPLATE`'s literal `{year}` placeholder is substituted
 * here; see {@see BnrEndpointNotConfiguredException} for why an unset template fails loudly
 * instead of guessing a URL.
 */
final readonly class BackfillBnrYear
{
    public function __construct(
        private ConfigRepository $config,
        private SourceIngestionService $ingestion,
        private BnrXmlParser $parser,
        private OfficialSourceRepository $officialSources,
        private FxRateImporter $importer,
    ) {
    }

    public function __invoke(int $year): FxImportSummary
    {
        $template = $this->config->get('sources.entries.BNR.endpoints.yearly_xml_template');

        if (! is_string($template) || $template === '') {
            throw BnrEndpointNotConfiguredException::yearly();
        }

        $url = str_replace('{year}', (string) $year, $template);

        $ingested = $this->ingestion->ingest('BNR', $url, 'FX_YEARLY', externalIdentifier: (string) $year, expectXml: true);
        $days = $this->parser->parse($ingested->rawBytes);

        $sourceId = $this->officialSources->idForCode('BNR');
        $summary = FxImportSummary::empty($ingested->isNewVersion);

        foreach ($days as $day) {
            $summary = $summary->withDayProcessed();

            foreach ($this->importer->import($sourceId, $day, $ingested->sourceDocumentVersionId) as $outcome) {
                $summary = $summary->withRate($outcome);
            }
        }

        // A rate stored can be a currency the list did not have yet.
        if ($summary->created > 0) {
            CurrencyCatalogue::forgetAvailable();
        }

        return $summary;
    }
}
