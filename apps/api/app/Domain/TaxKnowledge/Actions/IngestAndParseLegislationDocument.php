<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Actions;

use App\Domain\TaxKnowledge\DataTransferObjects\LegislationParsingResult;
use App\Domain\TaxKnowledge\Services\SourceIngestionService;

/**
 * The common end-to-end path: fetch through the existing, reused Milestone 2 allowlisted fetcher
 * ({@see SourceIngestionService::ingest()} — this task does not build a second fetcher, per its
 * brief), then structurally parse the resulting version. Deliberately re-reads the stored bytes
 * (via {@see ParseLegislationDocumentVersion}) rather than reusing `$ingested->rawBytes` directly,
 * so there is exactly one code path for "parse a version" whether it just landed or is being
 * re-parsed later — legislation pages are small enough (`docs/handoff/018-legislation-ingestion.md`)
 * that the extra round trip through storage is immaterial.
 *
 * Never sets an effective date — see {@see \App\Domain\TaxKnowledge\Services\LegislationSectionPersister}.
 * A caller who already knows the correct dates out-of-band should call
 * {@see ParseLegislationDocumentVersion} directly with them instead of this convenience wrapper.
 */
final readonly class IngestAndParseLegislationDocument
{
    public function __construct(
        private SourceIngestionService $ingestion,
        private ParseLegislationDocumentVersion $parseVersion,
    ) {
    }

    public function __invoke(
        string $sourceCode,
        string $url,
        string $documentType,
        ?string $externalIdentifier = null,
    ): LegislationParsingResult {
        $ingested = $this->ingestion->ingest($sourceCode, $url, $documentType, $externalIdentifier, expectXml: false);

        return ($this->parseVersion)($ingested->sourceDocumentVersionId);
    }
}
