<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Illuminate\Support\Carbon;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Config\ProgrammeOrders;
use Kern\Investments\Jobs\ReadTreasuryOrder;
use Kern\Investments\Models\TreasuryOrder;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\Legislatie\PortalAct;
use Kern\Legislation\Legislatie\PortalQuery;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Throwable;

/**
 * Each programme's orders, found on legislatie.just.ro and read as laws (ADR 0025 §5) — searched the way
 * the settings say, as the "Add a law" dialog searches. The first run searches every year from the
 * programme's first; every run after it this year, and last year too while this one is young.
 *
 * An order already a law is not read again; one that is a law but was never read for its issues — added
 * before this package, say — is read for them now. A year whose search fails is reported and the others
 * still searched: what one year missed, the next run finds.
 */
final readonly class OrderSearch
{
    public function __construct(
        private InvestmentsConfig $config,
        private PortalSearch $search,
        private SyncLegislationDocument $sync,
    ) {
    }

    /** @return list<string> what was done and what was not, a line each, for the run's output */
    public function run(): array
    {
        $lines = [];

        foreach ($this->config->programmes as $programme) {
            foreach ($this->years($programme) as $year) {
                try {
                    $lines = [...$lines, ...$this->searchYear($programme, $year)];
                } catch (Throwable $exception) {
                    $lines[] = "FAILED {$programme->programme->value} {$year}: the search did not finish ({$exception->getMessage()})";
                }
            }
        }

        return $lines;
    }

    /** @return list<int> */
    private function years(ProgrammeOrders $programme): array
    {
        $today = Carbon::today();
        $thisYear = $today->year;

        if (! TreasuryOrder::query()->where('programme', $programme->programme)->exists()) {
            return range($programme->firstYear, $thisYear);
        }

        return $today->dayOfYear <= $this->config->ordersOverlapDays ? [$thisYear - 1, $thisYear] : [$thisYear];
    }

    /** @return list<string> */
    private function searchYear(ProgrammeOrders $programme, int $year): array
    {
        $acts = array_filter(
            $this->search->all(PortalQuery::byTitle($programme->search, $year, $this->config->ordersActType)),
            static fn (PortalAct $act): bool => $programme->isTitled($act->title),
        );
        $started = 0;
        $read = 0;
        $failed = [];

        foreach ($acts as $act) {
            $known = SourceDocument::query()->where('portal_document_id', $act->portalId())->first();

            if ($known === null) {
                // One order that will not start is that order's failure, not the year's.
                try {
                    ($this->sync)($this->config->ordersActType, (int) $act->number, $act->year() ?? $year, null, $act->portalId());
                    $started++;
                } catch (Throwable $exception) {
                    $failed[] = "FAILED {$programme->programme->value} {$year}: order {$act->number} did not start reading ({$exception->getMessage()})";
                }
            } elseif (
                ! TreasuryOrder::query()->where('source_document_id', $known->id)->exists()
                && SourceDocumentVersion::query()->where('source_document_id', $known->id)->exists()
            ) {
                ReadTreasuryOrder::dispatch($known->id, $programme->programme)->onQueue($this->config->queue);
                $read++;
            }
        }

        return ["{$programme->programme->value} {$year}: " . count($acts) . " orders, {$started} new read as laws, {$read} already laws read for their issues", ...$failed];
    }
}
