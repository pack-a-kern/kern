<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Investments\Config\BvbSource;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Parsing\Bvb\BvbHistoryParser;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Fetching\Exceptions\HostNotAllowedException;
use Kern\Legislation\Fetching\FetchedDocument;
use Kern\Legislation\Services\SourceIngestionService;
use Kern\Platform\BrowserWorker\BrowserWorkerClient;
use LogicException;
use RuntimeException;

/**
 * A listed instrument's past prices, as the chart on its BVB page loads them (ADR 0025 §5): the page is
 * opened in the browser worker, its chart's six months asked for, and what the chart fetched kept as
 * evidence — each trading day's close, and how it moved from the day before. A day the daily import
 * already priced keeps that price — BVB's own list, to the minute — so this only fills the days before.
 */
final readonly class InstrumentHistory
{
    /** Decimals a move is kept to, as BVB's lists write one. */
    private const int CHANGE_SCALE = 2;

    /** The playbook's sequence that opens the page and keeps its chart's prices (`config/playbooks/bvb.php`). */
    private const string SEQUENCE = 'history';

    public function __construct(
        private InvestmentsConfig $config,
        private ConfigRepository $settings,
        private BrowserWorkerClient $worker,
        private OfficialSourceRegistry $sources,
        private SourceIngestionService $ingestion,
    ) {
    }

    /** @return int the days read */
    public function read(FinancialInstrument $instrument): int
    {
        $symbol = $instrument->symbol ?? throw new LogicException("Instrument {$instrument->id} has no symbol to read its prices by.");
        $url = $this->config->bvb->instrumentUrl($symbol);
        $playbook = $this->settings->get('playbooks.bvb');

        if (! is_array($playbook) || $playbook === []) {
            throw new RuntimeException('config/playbooks/bvb.php is missing: the browser has nothing to do on BVB\'s site.');
        }

        /** @var array<string, mixed> $playbook */
        $page = $this->worker->page($playbook, self::SEQUENCE, ['url' => $url], $this->settings->integer('sources.fetch.max_response_bytes'));

        // Where the chart's prices came from — checked against this source's own list, as every page is.
        if (! $this->sources->find(BvbSource::CODE)->allowsUrl($page->url)) {
            throw HostNotAllowedException::forUrl($page->url);
        }

        $kept = $this->ingestion->keep(BvbSource::CODE, $url, new FetchedDocument($page->body, $page->contentType, $page->url, 200), 'BVB_HISTORY', "BVB history {$symbol}");
        $now = now();
        $rows = [];
        $before = null;

        foreach (BvbHistoryParser::parse($kept->rawBytes) as $close) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'instrument_id' => $instrument->id,
                'trading_date' => $close->day->format('Y-m-d'),
                'price' => $close->close,
                'change_percent' => $before === null || bccomp($before->close, '0', 8) === 0
                    ? null
                    : bcdiv(bcmul(bcsub($close->close, $before->close, 8), '100', 8), $before->close, self::CHANGE_SCALE),
                // The chart dates a close by its day alone.
                'priced_at' => Carbon::instance($close->day),
                'evidence_id' => $kept->sourceDocumentVersionId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $before = $close;
        }

        DB::transaction(function () use ($rows, $instrument, $now): void {
            foreach (array_chunk($rows, $this->config->writeChunk) as $chunk) {
                DB::table('ext_instrument_prices')->insertOrIgnore($chunk);
            }

            $instrument->forceFill(['history_read_at' => $now])->save();
        });

        return count($rows);
    }
}
