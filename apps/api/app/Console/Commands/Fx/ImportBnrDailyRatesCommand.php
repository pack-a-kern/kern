<?php

declare(strict_types=1);

namespace App\Console\Commands\Fx;

use App\Domain\Fx\Actions\ImportBnrDailyRates;
use App\Domain\Fx\Exceptions\BnrEndpointNotConfiguredException;
use App\Domain\TaxKnowledge\Config\OfficialSourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Thin CLI wrapper over {@see ImportBnrDailyRates} (`BLUEPRINT.md` §7.2 `FetchBnrDailyRates`) — the
 * "start button" for the BNR daily import so it can be invoked by an operator or the scheduler
 * (`bootstrap/app.php`). Every decision (endpoint resolution, idempotency, anomaly detection)
 * already lives in the action; this class only resolves it, reports the resulting
 * {@see \App\Domain\Fx\DataTransferObjects\FxImportSummary}, and maps outcomes to an exit code.
 * No fiscal or business logic is added here (`docs/AGENTS.md` §6).
 */
final class ImportBnrDailyRatesCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:fx:import-daily
        {--dry-run : Validate the configured BNR endpoint and allowlist without fetching or writing anything}';

    /** @var string */
    protected $description = "Import today's BNR daily exchange rates (BLUEPRINT.md §7.2).";

    public function handle(ConfigRepository $config, OfficialSourceRegistry $registry, ImportBnrDailyRates $action): int
    {
        $url = $config->get('sources.entries.BNR.endpoints.daily_xml');

        if (! is_string($url) || $url === '') {
            $this->components->error(BnrEndpointNotConfiguredException::daily()->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($registry, $url);
        }

        try {
            $summary = $action();
        } catch (Throwable $exception) {
            $this->components->error('BNR daily import failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'BNR daily import complete: %d day(s) processed, %d rate(s) created, %d unchanged, %d anomaly(ies), document version %s.',
            $summary->daysProcessed,
            $summary->created,
            $summary->unchanged,
            $summary->anomalies,
            $summary->wasNewDocumentVersion ? 'new' : 'unchanged',
        ));

        if ($summary->anomalies > 0) {
            $this->components->warn(
                "{$summary->anomalies} anomaly/anomalies recorded in ext_fx_rate_anomalies — a previously stored rate changed value for the same date; review before trusting it.",
            );
        }

        return self::SUCCESS;
    }

    private function dryRun(OfficialSourceRegistry $registry, string $url): int
    {
        $source = $registry->find('BNR');
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $this->components->error("Configured BNR daily URL has no parseable host: {$url}");

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

        $this->components->info("Dry run OK: BNR enabled, host \"{$host}\" allowed. Would fetch {$url} and import today's rates.");

        return self::SUCCESS;
    }
}
