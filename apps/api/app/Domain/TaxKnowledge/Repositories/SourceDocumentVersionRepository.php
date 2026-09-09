<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Repositories;

use App\Domain\TaxKnowledge\DataTransferObjects\SourceDocumentVersionDetails;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceDocumentVersionException;
use App\Domain\TaxKnowledge\Models\OfficialSource;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use DateTimeZone;

/**
 * The provenance read surface other modules use — `Domain/Fx` calls
 * {@see detailsFor()} to answer "what raw document backs this rate", never by importing
 * {@see SourceDocumentVersion} directly (`docs/AGENTS.md` §6).
 */
final readonly class SourceDocumentVersionRepository
{
    public function detailsFor(string $id): SourceDocumentVersionDetails
    {
        /** @var SourceDocumentVersion|null $version */
        $version = SourceDocumentVersion::query()->find($id);

        if ($version === null) {
            throw UnknownSourceDocumentVersionException::forId($id);
        }

        /** @var SourceDocument $document */
        $document = SourceDocument::query()->findOrFail($version->source_document_id);

        /** @var OfficialSource $source */
        $source = OfficialSource::query()->findOrFail($document->official_source_id);

        return new SourceDocumentVersionDetails(
            id: $version->id,
            sourceCode: $source->code,
            contentSha256: $version->content_sha256,
            rawObjectPath: $version->raw_object_path,
            retrievedAt: $version->retrieved_at->setTimezone(new DateTimeZone('UTC'))->toDateTimeImmutable(),
        );
    }
}
