<?php

declare(strict_types=1);

namespace App\Console\Commands\Legislation;

use App\Domain\TaxKnowledge\Actions\IngestAndParseLegislationDocument;
use App\Domain\TaxKnowledge\Config\OfficialSourceRegistry;
use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Thin CLI wrapper over {@see IngestAndParseLegislationDocument} — fetch through the existing
 * allowlisted fetcher/source registry (`App\Domain\TaxKnowledge\Services\SourceIngestionService`,
 * M2), store immutably, and structurally parse into `source_sections` (M3,
 * `docs/handoff/018-legislation-ingestion.md`). No fiscal or parsing logic is added here
 * (`docs/AGENTS.md` §6) — this class only resolves the action, reports its
 * {@see \App\Domain\TaxKnowledge\DataTransferObjects\LegislationParsingResult}, and maps outcomes
 * to an exit code.
 *
 * Never sets an effective date (the action doesn't either — see that handoff note's "temporal
 * model" section): every `legislatie.just.ro` version this command produces ends up
 * `REQUIRES_REVIEW` by design, and that is reported, not treated as a failure.
 */
final class IngestLegislationDocumentCommand extends Command
{
    /** @var string */
    protected $signature = 'taxos:legislation:ingest
        {source : Official source code from config/sources.php, e.g. LEGISLATIE}
        {url : The document URL to fetch}
        {--type=LEGE : document_type recorded on source_documents (e.g. LEGE, DECIZIE, ORDIN)}
        {--external-id= : Optional external_identifier for source_documents}
        {--dry-run : Validate the source/host without fetching or writing anything}';

    /** @var string */
    protected $description = 'Fetch, store immutably, and structurally parse one legislation document (BLUEPRINT.md §9).';

    public function handle(OfficialSourceRegistry $registry, IngestAndParseLegislationDocument $ingest): int
    {
        $sourceCode = strtoupper((string) $this->argument('source'));
        $url = (string) $this->argument('url');
        $documentType = (string) $this->option('type');
        $externalId = $this->option('external-id');
        $externalId = is_string($externalId) && $externalId !== '' ? $externalId : null;

        try {
            $source = $registry->find($sourceCode);
        } catch (UnknownSourceException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $source->enabled) {
            $this->components->error("Source \"{$sourceCode}\" is disabled in config/sources.php.");

            return self::FAILURE;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $this->components->error("URL has no parseable host: {$url}");

            return self::FAILURE;
        }

        if (! $source->allowsHost($host)) {
            $this->components->error("Host \"{$host}\" is not in \"{$sourceCode}\"'s config/sources.php allowlist.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info(
                "Dry run OK: source \"{$sourceCode}\" enabled, host \"{$host}\" allowed. Would fetch {$url} as document_type \"{$documentType}\".",
            );

            return self::SUCCESS;
        }

        try {
            $result = $ingest($sourceCode, $url, $documentType, $externalId);
        } catch (Throwable $exception) {
            $this->components->error("Ingestion of {$url} failed: " . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Ingested %s (%s) — document version %s: %d source_sections, status %s%s.',
            $sourceCode,
            $url,
            $result->sourceDocumentVersionId,
            $result->sectionCount,
            $result->status->value,
            $result->publicationDate !== null ? ", publication date {$result->publicationDate}" : ', publication date unknown',
        ));

        if ($result->status === SourceDocumentVersionStatus::REQUIRES_REVIEW) {
            $this->components->warn(
                'Status is REQUIRES_REVIEW: legislatie.just.ro carries no machine-readable effective-date field, so no effective_from/until was set. '
                . 'A human must supply them (see docs/handoff/018-legislation-ingestion.md "The temporal model") before this version participates in a temporal query.',
            );
        }

        return self::SUCCESS;
    }
}
