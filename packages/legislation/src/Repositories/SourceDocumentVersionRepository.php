<?php

declare(strict_types=1);

namespace Kern\Legislation\Repositories;

use DateTimeZone;
use Kern\Legislation\DataTransferObjects\SourceDocumentVersionDetails;
use Kern\Legislation\Exceptions\UnknownSourceDocumentVersionException;
use Kern\Legislation\Models\OfficialSource;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;

/**
 * The provenance read surface other modules use — `Domain/Fx` calls
 * {@see detailsFor()} to answer "what raw document backs this rate", never by importing
 * {@see SourceDocumentVersion} directly (`docs/AGENTS.md` §6).
 */
final readonly class SourceDocumentVersionRepository
{
    public function detailsFor(string $id): SourceDocumentVersionDetails
    {
        return $this->detailsForMany([$id])[$id] ?? throw UnknownSourceDocumentVersionException::forId($id);
    }

    /**
     * {@see detailsFor()} for several versions at once: three queries, however many there are —
     * a month's rates come from as many versions as it has banking days.
     *
     * A version that does not exist is absent from the result; one whose document or source is
     * missing throws, as {@see detailsFor()} always has.
     *
     * @param  list<string>  $ids
     * @return array<string, SourceDocumentVersionDetails> by version id
     */
    public function detailsForMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<SourceDocumentVersion> $versions */
        $versions = SourceDocumentVersion::query()->whereKey(array_values(array_unique($ids)))->get()->all();

        /** @var array<string, SourceDocument> $documents */
        $documents = SourceDocument::query()
            ->findOrFail(array_values(array_unique(array_map(static fn (SourceDocumentVersion $version): string => $version->source_document_id, $versions))))
            ->keyBy('id')
            ->all();

        /** @var array<string, OfficialSource> $sources */
        $sources = OfficialSource::query()
            ->findOrFail(array_values(array_unique(array_map(static fn (SourceDocument $document): string => $document->official_source_id, $documents))))
            ->keyBy('id')
            ->all();

        $details = [];

        foreach ($versions as $version) {
            $details[$version->id] = new SourceDocumentVersionDetails(
                id: $version->id,
                sourceCode: $sources[$documents[$version->source_document_id]->official_source_id]->code,
                contentSha256: $version->content_sha256,
                rawObjectPath: $version->raw_object_path,
                retrievedAt: $version->retrieved_at->setTimezone(new DateTimeZone('UTC'))->toDateTimeImmutable(),
            );
        }

        return $details;
    }
}
