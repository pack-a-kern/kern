<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Kern\Legislation\Actions\ParseLegislationDocumentVersion;
use Kern\Legislation\Enums\ReadingStop;
use Kern\Legislation\Fetching\Exceptions\FetchSizeExceededException;
use Kern\Legislation\Fetching\Exceptions\TooManyRedirectsException;
use Kern\Legislation\Fetching\Exceptions\UnexpectedContentTypeException;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\Legislation\Legislatie\Exceptions\PortalBrowserException;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Parsing\Exceptions\UnparsableLegislationDocumentException;
use Kern\Legislation\Retrieval\Indexing\IndexDocumentVersionForRetrieval;
use Kern\Legislation\Services\SourceIngestionService;
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
 * The portal stalls and drops connections: a network failure, a 5xx or a page the browser could not
 * read is retried after the pauses in `legislation.sync.retry_seconds`. A consolidation that still
 * fails — or that is not there, or does not parse — is marked on the document's list and the next one
 * read. One bad page never stops a law, and the history names the gap it leaves
 * ({@see \Kern\Legislation\Services\BuildSectionHistory}).
 *
 * ## When this installation cannot
 *
 * Anything else is a failure on this side — storage, the database, the browser worker, the source turned
 * off — and is no consolidation's fault. Retried and marked, it would mark every consolidation of every
 * law it reaches. So the reading stops there: nothing is marked, the law says why ({@see ReadingStop}),
 * and it is read again by a person once the fault is fixed.
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
        $this->timeout = config()->integer('legislation.job_timeout_seconds');
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
            $ingested = $ingestion->ingest(self::SOURCE, $next['url'], $document->document_type, $document->external_identifier);
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
        } catch (Throwable $exception) {
            if (self::isThePortals($exception)) {
                throw $exception;
            }

            $this->stop($document, $exception);

            return;
        }

        self::dispatch($document->id);
    }

    /**
     * The portal's own failures, which another try may get past: no connection, a page too large or of
     * another kind than a law's, redirects without end, or the browser reading it busy or failing on it.
     */
    private static function isThePortals(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || $exception instanceof FetchSizeExceededException
            || $exception instanceof UnexpectedContentTypeException
            || $exception instanceof TooManyRedirectsException
            || ($exception instanceof PortalBrowserException && $exception->reason !== PortalBrowserException::UNAVAILABLE);
    }

    /** This installation failed, not the page: the reading ends here, the law says why, nothing is marked. */
    private function stop(SourceDocument $document, Throwable $exception): void
    {
        report($exception);
        $document->forceFill(['reading_stopped' => ReadingStop::of($exception), 'reading_stopped_at' => now()])->save();
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
     * @return array{date: string, ref: string, url: string}|null
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
                return ['date' => $entry['date'], 'ref' => $entry['ref'], 'url' => $entry['url']];
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
