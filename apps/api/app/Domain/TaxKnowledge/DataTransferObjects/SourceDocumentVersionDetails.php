<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\DataTransferObjects;

use DateTimeImmutable;

/**
 * Provenance detail for one `source_document_versions` row, resolved by
 * {@see \App\Domain\TaxKnowledge\Repositories\SourceDocumentVersionRepository::detailsFor()} —
 * the cross-module read surface `Domain/Fx` uses to answer "what raw document, and from which
 * source, backs this rate" (`BLUEPRINT.md` §26 DoD: "raw XML + hash + provenance disponibilă").
 */
final readonly class SourceDocumentVersionDetails
{
    public function __construct(
        public string $id,
        public string $sourceCode,
        public string $contentSha256,
        public string $rawObjectPath,
        public DateTimeImmutable $retrievedAt,
    ) {
    }
}
