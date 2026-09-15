<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\DataTransferObjects;

use DateTimeImmutable;

/**
 * The single `ext_source_document_versions` row
 * {@see \App\Domain\TaxKnowledge\Services\LegislationTemporalQuery::versionInForceOn()} resolved
 * for a given document + date — never ambiguous, never a guess (see that service's docblock).
 */
final readonly class EffectiveDocumentVersion
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public string $contentSha256,
    ) {
    }
}
