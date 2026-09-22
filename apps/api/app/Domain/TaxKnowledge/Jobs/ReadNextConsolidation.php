<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Jobs;

use App\Domain\TaxKnowledge\Actions\ParseLegislationDocumentVersion;
use App\Domain\TaxKnowledge\Fetching\Exceptions\UnexpectedStatusException;
use App\Domain\TaxKnowledge\Legislatie\ConsolidationPages;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Parsing\Exceptions\UnparsableLegislationDocumentException;
use App\Domain\TaxKnowledge\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use App\Domain\TaxKnowledge\Services\SourceIngestionService;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the oldest consolidation of a law not stored yet, then queues itself for the next.
 *
 * One consolidation per job — downloaded, stored as it was served, parsed into sections dated from
 * the consolidation's own date, and indexed for search — so a law of ninety consolidations is ninety
 * short jobs rather than one that no timeout would allow. When none is left,
 * {@see FinishLegislationSync} dates every version against the next and rebuilds the history.
 *
 * ## When a consolidation cannot be read
 *
 * The portal stalls and drops connections: a network failure or a 5xx is retried after the pauses in
 * `legislation.sync.retry_seconds`. A consolidation that still fails — or that is not there, or does
 * not parse — is marked on the document's list and the next one read. One bad page never stops a
 * law, and the history names the gap it leaves ({@see \App\Domain\TaxKnowledge\Services\BuildSectionHistory}).
 */
final class ReadNextConsolidation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const string SOURCE = 'LEGISLATIE';

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $sourceDocumentId)
    {
        $this->tries = 1 + count($this->backoff());
        $this->timeout = config()->integer('horizon.defaults.supervisor-legislation.timeout');
        $this->onQueue(config()->string('legislation.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        /** @var list<int> $pauses */
        $pauses = config()->array('legislation.sync.retry_seconds');

        return $pauses;
    }

    public function handle(
        SourceIngestionService $ingestion,
        ParseLegislationDocumentVersion $parse,
        IndexDocumentVersionForRetrieval $index,
        ConsolidationPages $pages,
    ): void {
        $document = SourceDocument::query()->find($this->sourceDocumentId);
        $next = $document === null ? null : self::next($document);

        if ($document === null) {
            return;
        }

        if ($next === null) {
            FinishLegislationSync::dispatch($document->id);

            return;
        }

        try {
            // A consolidation that is only a reference is read as the text it refers to.
            $url = ($next['through_reference'] ?? false) === true ? ($pages->referencedText($next['url']) ?? $next['url']) : $next['url'];
            $ingested = $ingestion->ingest(self::SOURCE, $url, $document->document_type, $document->external_identifier);
            $version = SourceDocumentVersion::query()->findOrFail($ingested->sourceDocumentVersionId);

            // Byte-identical to a consolidation already stored under another date: the portal
            // served one page for two entries. Not a second version of the law.
            if ($version->consolidation_ref !== null && $version->consolidation_ref !== $next['ref']) {
                $this->markFailed($document, $next['ref'], 'same content as consolidation ' . $version->consolidation_ref);
                self::dispatch($document->id);

                return;
            }

            $parse($version->id, new DateTimeImmutable($next['date']));
            $index($version->id);

            // Last: a consolidation counts as read only once its sections and chunks are there, so
            // one that failed half-way is read again rather than skipped.
            $version->forceFill(['consolidation_ref' => $next['ref']])->save();
        } catch (UnparsableLegislationDocumentException $exception) {
            $this->markFailed($document, $next['ref'], $exception->getMessage());
        } catch (UnexpectedStatusException $exception) {
            // A page that is not there will not be there on the next try either; a server that
            // failed, or asked to be left alone, may be.
            if ($exception->status < 500 && ! in_array($exception->status, [408, 429], true)) {
                $this->markFailed($document, $next['ref'], $exception->getMessage());
            } else {
                throw $exception;
            }
        }

        self::dispatch($document->id);
    }

    /** The retries are spent: this consolidation is marked, and the law carries on without it. */
    public function failed(?Throwable $exception): void
    {
        $document = SourceDocument::query()->find($this->sourceDocumentId);
        $next = $document === null ? null : self::next($document);

        if ($document === null || $next === null) {
            return;
        }

        $this->markFailed($document, $next['ref'], $exception?->getMessage() ?? 'failed');
        self::dispatch($document->id);
    }

    /**
     * The oldest listed consolidation that is neither stored nor marked failed.
     *
     * @return array{date: string, ref: string, url: string, through_reference?: bool}|null
     */
    private static function next(SourceDocument $document): ?array
    {
        $stored = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereNotNull('consolidation_ref')
            ->pluck('consolidation_ref')
            ->all();

        foreach ($document->consolidations ?? [] as $entry) {
            if (($entry['failed'] ?? false) !== true && ! in_array($entry['ref'], $stored, true)) {
                return $entry;
            }
        }

        return null;
    }

    private function markFailed(SourceDocument $document, string $ref, string $reason): void
    {
        $document->consolidations = array_map(
            static fn (array $entry): array => $entry['ref'] === $ref ? [...$entry, 'failed' => true] : $entry,
            $document->consolidations ?? [],
        );
        $document->save();

        // The portal's id and the reason only: the reason is the fetcher's or the parser's own
        // sentence about the page, never its content.
        Log::warning('legislation_consolidation_failed', ['document_id' => $document->id, 'ref' => $ref, 'reason' => $reason]);
    }
}
