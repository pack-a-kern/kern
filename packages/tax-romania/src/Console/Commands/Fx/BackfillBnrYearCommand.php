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
        {year : Fiscal year to backfill, e.g. 2025 — BNR archives are available for 2005-2026 (docs/reference/bnr-fx-endpoints.md)}
        {--dry-run : Validate the configured BNR yearly endpoint and allowlist without fetching or writing anything}';

    /** @var string */
    protected $description = "Backfill one year's BNR exchange rates from the yearly archive (BLUEPRINT.md §7.3).";

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

        try {
            $summary = $action($year);
        } catch (Throwable $exception) {
            $this->components->error("BNR backfill for {$year} failed: " . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'BNR backfill %d complete: %d banking day(s) processed, %d rate(s) created, %d unchanged, %d anomaly(ies), document version %s.',
            $year,
            $summary->daysProcessed,
            $summary->created,
            $summary->unchanged,
            $summary->anomalies,
            $summary->wasNewDocumentVersion ? 'new' : 'unchanged',
        ));

        if ($summary->anomalies > 0) {
            $this->components->warn(
                "{$summary->anomalies} anomaly/anomalies recorded in ext_fx_rate_anomalies — review before trusting the affected date(s).",
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
