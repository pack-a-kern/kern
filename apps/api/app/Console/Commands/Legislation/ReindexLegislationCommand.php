<?php

declare(strict_types=1);

namespace App\Console\Commands\Legislation;

use App\Domain\TaxKnowledge\Actions\ParseLegislationDocumentVersion;
use App\Domain\TaxKnowledge\Jobs\EmbedLegislationChunks;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
use App\Domain\TaxKnowledge\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use App\Domain\TaxKnowledge\Services\BuildSectionHistory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuilds `ext_source_sections` and `ext_source_section_chunks` from already-stored raw bytes — no
 * network fetch, ever (`docs/AGENTS.md` rule 17: the raw source is never re-fetched just to
 * re-derive an index of it). This is the "start button" for two already-built, previously
 * uninvoked capabilities:
 * {@see ParseLegislationDocumentVersion} (re-parse under a newer `LegislatieJustRoHtmlParser`
 * version — `docs/handoff/018-legislation-ingestion.md`) and
 * {@see IndexDocumentVersionForRetrieval} (rebuild the chunk/retrieval index —
 * `docs/handoff/029-hybrid-retrieval.md`), then each law's provision history. No parsing/chunking logic is added here
 * (`docs/AGENTS.md` §6).
 *
 * **Scoped to the `LEGISLATIE` source only.** `LegislatieJustRoHtmlParser` is verified only
 * against `legislatie.just.ro`'s own markup (see that parser's docblock); running it against a
 * `BNR` XML version or an unparsed `ANAF`/`MF` PDF version would not be "reindexing", it would be
 * feeding the wrong parser the wrong bytes. A future task that builds a parser for another source
 * extends this scope explicitly; this command does not guess that it already applies.
 *
 * **Preserves each version's existing `effective_from`/`effective_until`.**
 * {@see ParseLegislationDocumentVersion} persists exactly the dates it is given, defaulting to
 * `null` — passing nothing here would silently erase a consolidation's date. This
 * command always reads the version's current dates first and passes them straight back through.
 */
final class ReindexLegislationCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:legislation:reindex
        {--document-version= : Reindex only this ext_source_document_versions id (default: every LEGISLATIE-sourced version)}
        {--embed : Also queue embedding the new chunks — identical texts reuse the vector they already have}
        {--dry-run : Report what would be reindexed without writing anything}';

    /** @var string */
    protected $description = 'Rebuild ext_source_sections and the retrieval chunk index from already-stored legislation bytes.';

    public function handle(
        OfficialSourceRepository $officialSources,
        ParseLegislationDocumentVersion $reparse,
        IndexDocumentVersionForRetrieval $indexVersion,
        BuildSectionHistory $history,
    ): int {
        $legislatieSourceId = $officialSources->idForCode('LEGISLATIE');

        $query = SourceDocumentVersion::query()
            ->whereIn('source_document_id', SourceDocument::query()
                ->where('official_source_id', $legislatieSourceId)
                ->select('id'));

        $documentVersionOption = $this->option('document-version');

        if (is_string($documentVersionOption) && $documentVersionOption !== '') {
            $query->where('id', $documentVersionOption);
        }

        /** @var list<SourceDocumentVersion> $versions */
        $versions = $query->orderBy('retrieved_at')->get()->all();

        if ($versions === [] && is_string($documentVersionOption) && $documentVersionOption !== '') {
            $this->components->error(
                "No LEGISLATIE ext_source_document_versions row with id \"{$documentVersionOption}\" was found (or it belongs to a different source).",
            );

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $sectionCount = SourceSection::query()
                ->whereIn('document_version_id', array_map(static fn (SourceDocumentVersion $version): string => $version->id, $versions))
                ->count();

            $this->components->info(sprintf(
                'Dry run: would reindex %d document version(s) covering %d existing source_section(s). No writes performed.',
                count($versions),
                $sectionCount,
            ));

            return self::SUCCESS;
        }

        $totalSections = 0;
        $totalChunks = 0;

        foreach ($versions as $version) {
            try {
                $result = $reparse(
                    $version->id,
                    $version->effective_from?->toDateTimeImmutable(),
                    $version->effective_until?->toDateTimeImmutable(),
                );
            } catch (Throwable $exception) {
                $this->components->error("Re-parsing version {$version->id} failed: " . $exception->getMessage());

                return self::FAILURE;
            }

            $totalSections += $result->sectionCount;

            $chunkCount = $indexVersion($version->id);
            $totalChunks += $chunkCount;

            $this->components->info(sprintf(
                'Reindexed version %s: %d source_section(s), %d chunk(s), status %s.',
                $version->id,
                $result->sectionCount,
                $chunkCount,
                $result->status->value,
            ));

        }

        // Re-parsing can change what a provision's fingerprint reads, so each law's history is
        // rebuilt from the sections just written.
        foreach (array_unique(array_map(static fn (SourceDocumentVersion $version): string => $version->source_document_id, $versions)) as $documentId) {
            $history($documentId);
        }

        if ($this->option('embed')) {
            EmbedLegislationChunks::dispatch();
            $this->components->info('Embedding queued on "' . config()->string('legislation.queue') . '".');
        }

        $this->components->info(sprintf(
            'Reindex complete: %d document version(s), %d source_section(s), %d chunk(s).',
            count($versions),
            $totalSections,
            $totalChunks,
        ));

        return self::SUCCESS;
    }
}
