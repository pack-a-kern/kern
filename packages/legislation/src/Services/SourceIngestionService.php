<?php

declare(strict_types=1);

namespace Kern\Legislation\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Kern\Legislation\DataTransferObjects\IngestedSourceDocument;
use Kern\Legislation\Fetching\Exceptions\UnexpectedContentTypeException;
use Kern\Legislation\Fetching\FetchedDocument;
use Kern\Legislation\Fetching\PageFetcher;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Storage\RawSourceObjectStore;

/**
 * The module's public entry point (`docs/AGENTS.md` §6 — other modules depend on an application
 * service, never on this module's Eloquent models directly). Implements the full ingestion
 * pipeline `BLUEPRINT.md` §9/§7.2 describes: fetch through the host allowlist, sanity-check the
 * response, store the raw bytes immutably and content-addressed, and record a `ext_source_documents`/
 * `ext_source_document_versions` row — creating a new version only if the content actually changed.
 *
 * Idempotent by construction: re-ingesting byte-identical content resolves to the same
 * content-addressed storage path ({@see RawSourceObjectStore}) and the same
 * `(source_document_id, content_sha256)` unique row, so `isNewVersion` comes back `false` and no
 * new row is written — this is the milestone's "importing the same document twice changes
 * nothing" requirement, enforced structurally rather than by a separate "did anything change"
 * check.
 *
 * `$expectXml` exists because this milestone's only caller (`Domain/Fx`'s BNR importer) fetches
 * XML exclusively, and the observed BNR failure mode (`docs/reference/bnr-fx-endpoints.md`) is
 * specifically an XML URL silently 302-redirecting to the HTML homepage — BLUEPRINT.md §7.2 step 3
 * calls this "size/mime sanity" out as its own pipeline step. It defaults to `false` and performs
 * no content-shape assertion at all, deliberately, so this service stays reusable by Milestone 3's
 * legislation ingestion (HTML/PDF) without this milestone hardcoding a check that would reject
 * every one of that milestone's fetches.
 */
final readonly class SourceIngestionService
{
    public function __construct(
        private PageFetcher $fetcher,
        private RawSourceObjectStore $rawStore,
        private OfficialSourceRepository $sources,
    ) {
    }

    public function ingest(
        string $sourceCode,
        string $url,
        string $documentType,
        ?string $externalIdentifier = null,
        bool $expectXml = false,
    ): IngestedSourceDocument {
        $fetched = $this->fetcher->fetch($sourceCode, $url);

        if ($expectXml) {
            $this->assertLooksLikeXml($url, $fetched);
        }

        return $this->keep($sourceCode, $url, $fetched, $documentType, $externalIdentifier);
    }

    /**
     * A document fetched some other way — what a page fetched when the browser worker opened it — kept as
     * evidence exactly as one this service fetched itself, under `$url`, the address a person would open.
     */
    public function keep(
        string $sourceCode,
        string $url,
        FetchedDocument $fetched,
        string $documentType,
        ?string $externalIdentifier = null,
    ): IngestedSourceDocument {
        $officialSourceId = $this->sources->idForCode($sourceCode);
        $stored = $this->rawStore->store($sourceCode, $fetched->body);

        $sourceDocument = SourceDocument::query()->firstOrCreate(
            [
                'official_source_id' => $officialSourceId,
                'document_type' => $documentType,
                'external_identifier' => $externalIdentifier,
            ],
            [
                'canonical_url' => $url,
            ],
        );

        $existingVersion = SourceDocumentVersion::query()
            ->where('source_document_id', $sourceDocument->id)
            ->where('content_sha256', $stored->sha256)
            ->first();

        if ($existingVersion !== null) {
            return new IngestedSourceDocument(
                sourceDocumentVersionId: $existingVersion->id,
                contentSha256: $stored->sha256,
                rawObjectPath: $existingVersion->raw_object_path,
                retrievedAt: $existingVersion->retrieved_at->toDateTimeImmutable(),
                rawBytes: $fetched->body,
                isNewVersion: false,
            );
        }

        $version = $this->createVersionRow($sourceDocument, $stored->sha256, $stored->path, $stored->sizeBytes, $fetched->contentType);

        return new IngestedSourceDocument(
            sourceDocumentVersionId: $version->id,
            contentSha256: $stored->sha256,
            rawObjectPath: $version->raw_object_path,
            retrievedAt: $version->retrieved_at->toDateTimeImmutable(),
            rawBytes: $fetched->body,
            isNewVersion: true,
        );
    }

    /**
     * A concurrent ingestion of the same brand-new content could race between the "does this
     * version exist" read above and this insert; the unique index on `(source_document_id,
     * content_sha256)` (`2026_09_08_300002_create_source_document_versions_table.php`) is the
     * real guard — this catches the resulting constraint violation and returns the row the other
     * request created instead of failing the whole ingestion.
     */
    private function createVersionRow(
        SourceDocument $sourceDocument,
        string $sha256,
        string $rawObjectPath,
        int $sizeBytes,
        ?string $contentType,
    ): SourceDocumentVersion {
        // `supersedes_version_id` (Milestone 3, docs/handoff/018-legislation-ingestion.md) is a
        // plain, knowable lineage fact — "which row was the latest for this document immediately
        // before this insert" — never an effective-date inference. Read before the insert to avoid
        // a version ever pointing at itself under a races; a benign race (two concurrent
        // first-time ingests of the same brand-new document) at worst leaves `supersedes_version_id`
        // null on the row that loses the race to be "previous", which is correct, not a defect.
        $previousLatest = SourceDocumentVersion::query()
            ->where('source_document_id', $sourceDocument->id)
            ->orderByDesc('retrieved_at')
            ->first();

        try {
            return SourceDocumentVersion::query()->create([
                'source_document_id' => $sourceDocument->id,
                'retrieved_at' => CarbonImmutable::now('UTC'),
                'content_sha256' => $sha256,
                'raw_object_path' => $rawObjectPath,
                'size_bytes' => $sizeBytes,
                'content_type' => $contentType,
                'supersedes_version_id' => $previousLatest?->id,
            ]);
        } catch (QueryException $exception) {
            $existing = SourceDocumentVersion::query()
                ->where('source_document_id', $sourceDocument->id)
                ->where('content_sha256', $sha256)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    private function assertLooksLikeXml(string $url, FetchedDocument $fetched): void
    {
        $contentType = $fetched->contentType ?? '';
        $looksLikeXmlType = str_contains(strtolower($contentType), 'xml');
        $looksLikeXmlBody = str_starts_with(ltrim($fetched->body), '<?xml') || str_starts_with(ltrim($fetched->body), '<');
        $looksLikeHtml = str_contains(strtolower(ltrim($fetched->body)), '<!doctype html') || str_contains(strtolower(ltrim($fetched->body)), '<html');

        if ($looksLikeHtml || ! ($looksLikeXmlType || $looksLikeXmlBody)) {
            throw UnexpectedContentTypeException::expectedXml($url, $fetched->contentType);
        }
    }
}
