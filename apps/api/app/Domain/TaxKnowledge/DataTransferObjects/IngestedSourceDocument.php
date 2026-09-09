<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\DataTransferObjects;

use DateTimeImmutable;

/**
 * What {@see \App\Domain\TaxKnowledge\Services\SourceIngestionService::ingest()} hands back to a
 * caller — deliberately a DTO, never the {@see \App\Domain\TaxKnowledge\Models\SourceDocumentVersion}
 * Eloquent model itself, so a cross-module caller (`Domain/Fx`) never needs to import this
 * module's models (`docs/AGENTS.md` §6).
 *
 * `rawBytes` is included directly (not re-read from storage by the caller) because every document
 * this milestone ingests — a daily or yearly BNR XML feed — is small enough to hold in memory
 * once already, and doing so avoids `Domain/Fx` needing any dependency on
 * {@see \App\Domain\TaxKnowledge\Storage\RawSourceObjectStore} at all.
 */
final readonly class IngestedSourceDocument
{
    public function __construct(
        public string $sourceDocumentVersionId,
        public string $contentSha256,
        public string $rawObjectPath,
        public DateTimeImmutable $retrievedAt,
        public string $rawBytes,
        public bool $isNewVersion,
    ) {
    }
}
