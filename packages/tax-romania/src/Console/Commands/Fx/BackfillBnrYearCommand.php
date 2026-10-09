<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Console\Commands\Fx;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\TaxRomania\Fx\Actions\BackfillBnrYear;
use Kern\TaxRomania\Fx\Exceptions\BnrEndpointNotConfiguredException;
use Throwable;

/**
 * Thin CLI wrapper over {@see BackfillBnrYear} (`BLUEPRINT.md` §7.3 `BackfillBnrYear(year)`) — the
 * "start button" for a one-off historical import. No fiscal or business logic is added here
 * (`docs/AGENTS.md` §6); every decision already lives in the action.
 */
final class BackfillBnrYearCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:fx:backfill
        {year : The first year to backfill — every year from it to the current one is fetched. BNR archives run from 2005 (docs/reference/bnr-fx-endpoints.md)}
        {--dry-run : Validate the configured BNR yearly endpoint and allowlist without fetching or writing anything}';

    /** @var string */
    protected $description = 'Backfill BNR exchange rates from a given year to the present (BLUEPRINT.md §7.3).';

    public function handle(ConfigRepository $config, OfficialSourceRegistry $registry, BackfillBnrYear $action): int
    {
        $yearArgument = $this->argument('year');

        // `year` is a required argument, so it is always a string here; only its shape needs checking.
        if (! ctype_digit($yearArgument)) {
            $this->components->error("Year must be a positive integer, got \"{$yearArgument}\".");

            return self::FAILURE;
        }

        $year = (int) $yearArgument;

        $template = $config->get('sources.entries.BNR.endpoints.yearly_xml_template');

        if (! is_string($template) || $template === '') {
            $this->components->error(BnrEndpointNotConfiguredException::yearly()->getMessage());

            return self::FAILURE;
        }

        $url = str_replace('{year}', (string) $year, $template);

        if ($this->option('dry-run')) {
            return $this->dryRun($registry, $year, $url);
        }

        /*
         * The year given is where to start, not the only year to fetch.
         *
         * An operator asking to backfill 2023 is saying "the rates from 2023 are missing or wrong", and the
         * years after it are missing or wrong for the same reason — a gap discovered in 2023 was never a gap
         * in 2023 alone. Fetching only the year typed left the operator to type each following year in turn
         * and to notice when they had finished.
         *
         * Safe to repeat: the action reports created against unchanged, and a year already correct adds
         * nothing. That is what makes "from here to now" a reasonable default rather than a lot of work.
         */
        $through = (int) now('UTC')->format('Y');
        $created = 0;
        $unchanged = 0;
        $anomalies = 0;
        $days = 0;

        foreach (range($year, max($year, $through)) as $each) {
            try {
                $summary = $action($each);
            } catch (Throwable $exception) {
                /*
                 * The years already done are kept. A failure in 2025 does not undo 2023 and 2024, and
                 * saying which year broke is the difference between a retry and a reread of the logs.
                 */
                $this->components->error("BNR backfill for {$each} failed: " . $exception->getMessage());

                return self::FAILURE;
            }

            $days += $summary->daysProcessed;
            $created += $summary->created;
            $unchanged += $summary->unchanged;
            $anomalies += $summary->anomalies;

            $this->components->twoColumnDetail(
                (string) $each,
                sprintf('%d day(s), %d created, %d unchanged', $summary->daysProcessed, $summary->created, $summary->unchanged),
            );
        }

        $this->components->info(sprintf(
            'BNR backfill %d-%d complete: %d banking day(s) processed, %d rate(s) created, %d unchanged, %d anomaly(ies).',
            $year,
            $through,
            $days,
            $created,
            $unchanged,
            $anomalies,
        ));

        if ($anomalies > 0) {
            $this->components->warn(
                "{$anomalies} anomaly/anomalies recorded in ext_fx_rate_anomalies — review before trusting the affected date(s).",
            );
        }

        return self::SUCCESS;
    }

    private function dryRun(OfficialSourceRegistry $registry, int $year, string $url): int
    {
        $source = $registry->find('BNR');
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $this->components->error("Resolved BNR yearly URL has no parseable host: {$url}");

            return self::FAILURE;
        }

        if (! $source->enabled) {
            $this->components->error('BNR source is disabled in config/sources.php.');

            return self::FAILURE;
        }

        if (! $source->allowsHost($host)) {
            $this->components->error("Host \"{$host}\" is not in BNR's config/sources.php allowlist.");

            return self::FAILURE;
        }

        $this->components->info("Dry run OK: BNR enabled, host \"{$host}\" allowed. Would fetch {$url} and backfill {$year}.");

        return self::SUCCESS;
    }
}
