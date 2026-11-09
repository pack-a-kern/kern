<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Illuminate\Support\Facades\DB;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Config\SubscriptionLists;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Parsing\ParseFailure;
use Kern\Investments\Parsing\Treasury\SubscriptionFigures;
use Kern\Investments\Parsing\Treasury\SubscriptionListParser;
use Kern\Investments\Processing\SpreadsheetGrid;
use Kern\Legislation\Services\SourceIngestionService;

/**
 * The Ministry of Finance's subscription lists: what each issue raised and from how many (ADR 0025 §5).
 *
 * The page is read for each list's link, each list kept as evidence; a list whose bytes were read before
 * is not read again. A line gives its figures to the instrument of its code — by the code alone, and by
 * the month only where the list holds that code more than once. What does not match for sure is skipped
 * and counted, never guessed: the figures are information, and an instrument is whole without them.
 */
final readonly class SubscriptionFiguresImport
{
    public function __construct(
        private InvestmentsConfig $config,
        private SourceIngestionService $ingestion,
        private SpreadsheetGrid $grid,
    ) {
    }

    /** @return list<string> a line per list, for the run's output */
    public function run(): array
    {
        $lists = $this->config->subscriptions;
        $page = $this->ingestion->ingest(SubscriptionLists::SOURCE, $lists->pageUrl, 'SUBSCRIPTIONS_PAGE', 'subscriptions page');
        $lines = [];

        foreach (Programme::cases() as $programme) {
            $link = $lists->linkIn($page->rawBytes, $programme) ?? throw ParseFailure::at('subscriptions page', "no link to the {$programme->value} list");
            $file = $this->ingestion->ingest(SubscriptionLists::SOURCE, $link, 'SUBSCRIPTIONS_LIST', "{$programme->value} subscriptions");

            $lines[] = $file->isNewVersion
                ? $this->write($programme, SubscriptionListParser::parse($programme, $this->grid->rows($file->rawBytes)))
                : "{$programme->value} subscriptions: unchanged since last read";
        }

        return $lines;
    }

    /** @param list<SubscriptionFigures> $figures */
    private function write(Programme $programme, array $figures): string
    {
        $byCode = [];

        foreach ($figures as $line) {
            $byCode[$line->code][] = $line;
        }

        $codeColumn = $programme === Programme::FIDELIS ? 'symbol' : 'issue_code';
        $matched = 0;
        $skipped = 0;

        DB::transaction(function () use ($programme, $byCode, $codeColumn, &$matched, &$skipped): void {
            foreach (array_chunk(array_keys($byCode), $this->config->writeChunk) as $codes) {
                $instruments = FinancialInstrument::query()->where('programme', $programme)->whereIn($codeColumn, $codes)->get();

                foreach ($instruments as $instrument) {
                    $code = $programme === Programme::FIDELIS ? $instrument->symbol : $instrument->issue_code;
                    $line = $code === null ? null : self::lineFor($instrument, $byCode[$code]);

                    if ($line === null) {
                        continue;
                    }

                    $instrument->forceFill([
                        'subscribed_value_minor' => $line->subscribedValueMinor,
                        'subscriptions_count' => $line->subscriptions,
                        'subscriptions_read_at' => now(),
                    ])->save();
                    $matched++;
                }
            }

            $skipped = count(array_merge(...array_values($byCode))) - $matched;
        });

        return "{$programme->value} subscriptions: " . count($figures) . " lines, {$matched} matched, {$skipped} skipped as not matching for sure";
    }

    /**
     * The one line of the instrument's code — or, the code listed more than once, the one line of its issue
     * month. Anything less certain is no line.
     *
     * @param non-empty-list<SubscriptionFigures> $lines
     */
    private static function lineFor(FinancialInstrument $instrument, array $lines): ?SubscriptionFigures
    {
        if (count($lines) === 1) {
            return $lines[0];
        }

        $month = $instrument->issue_date?->format('Y-m');
        $inMonth = array_values(array_filter(
            $lines,
            static fn (SubscriptionFigures $line): bool => in_array($month, array_map(static fn ($m): string => $m->format('Y-m'), $line->months), true),
        ));

        return count($inMonth) === 1 ? $inMonth[0] : null;
    }
}
