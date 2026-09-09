<?php

declare(strict_types=1);

namespace App\Domain\Fx\Actions;

use App\Domain\Fx\DataTransferObjects\FxImportSummary;
use App\Domain\Fx\Exceptions\BnrEndpointNotConfiguredException;
use App\Domain\Fx\Parsing\BnrXmlParser;
use App\Domain\Fx\Repositories\FxSourceRepository;
use App\Domain\Fx\Services\FxRateImporter;
use App\Domain\TaxKnowledge\Services\SourceIngestionService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

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
        private FxSourceRepository $fxSources,
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

        $source = $this->fxSources->resolve('BNR');
        $summary = FxImportSummary::empty($ingested->isNewVersion);

        foreach ($days as $day) {
            $summary = $summary->withDayProcessed();

            foreach ($day->rates as $rate) {
                $summary = $summary->withRate(
                    $this->importer->import($source, $day->date, $rate, $ingested->sourceDocumentVersionId),
                );
            }
        }

        return $summary;
    }
}
