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
use RuntimeException;

/**
 * `BLUEPRINT.md` §7.2 `FetchBnrDailyRates` — the full pipeline for one day's BNR feed: resolve the
 * configured URL (never a hardcoded one — see {@see BnrEndpointNotConfiguredException}), ingest
 * it through the source registry, parse it, and idempotently import every currency's rate.
 *
 * Deliberately a plain, directly callable, synchronously testable class — not itself a queued
 * job. `Kern\TaxRomania\Fx\Jobs\FetchBnrDailyRates` is the thin `ShouldQueue` wrapper matching the
 * blueprint's naming; scheduling it (`routes/console.php`) is outside this task's scope (see
 * `docs/handoff/014-source-registry-bnr.md` "What is NOT done").
 */
final readonly class ImportBnrDailyRates
{
    public function __construct(
        private ConfigRepository $config,
        private SourceIngestionService $ingestion,
        private BnrXmlParser $parser,
        private OfficialSourceRepository $officialSources,
        private FxRateImporter $importer,
    ) {
    }

    public function __invoke(): FxImportSummary
    {
        $url = $this->config->get('sources.entries.BNR.endpoints.daily_xml');

        if (! is_string($url) || $url === '') {
            throw BnrEndpointNotConfiguredException::daily();
        }

        $ingested = $this->ingestion->ingest('BNR', $url, 'FX_DAILY', expectXml: true);
        $days = $this->parser->parse($ingested->rawBytes);

        if (count($days) !== 1) {
            throw new RuntimeException(sprintf(
                'Expected exactly one <Cube date="..."> in a daily BNR feed, found %d.',
                count($days),
            ));
        }

        $day = $days[0];
        $sourceId = $this->officialSources->idForCode('BNR');
        $summary = FxImportSummary::empty($ingested->isNewVersion)->withDayProcessed();

        foreach ($day->rates as $rate) {
            $summary = $summary->withRate(
                $this->importer->import($sourceId, $day->date, $rate, $ingested->sourceDocumentVersionId),
            );
        }

        // A rate stored can be a currency the list did not have yet.
        if ($summary->created > 0) {
            CurrencyCatalogue::forgetAvailable();
        }

        return $summary;
    }
}
