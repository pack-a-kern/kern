<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Chunking;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Enums\TrustTier;
use DateTimeImmutable;

/**
 * Input to {@see SourceSectionChunker::chunk()} — every `BLUEPRINT.md` §15.3 metadata field this
 * module can honestly populate today, assembled by {@see SourceSectionChunkAssembler} from the
 * database. `tax_years`/`subject_types`/`tax_types` from §15.3's fuller list are deliberately
 * absent: nothing in this or any earlier milestone classifies a section against those yet
 * (`fiscal_parameter_versions`/`tax_rule_sources` linkage is Milestone 4/5's job) — carrying an
 * always-null placeholder for them would be exactly the unused, speculative field
 * `docs/CODE-QUALITY.md` §2 forbids (the same judgement `docs/handoff/014-source-registry-bnr.md`
 * applied to `ext_fx_rate_anomalies.resolved_at`). Whoever wires that classification extends this DTO
 * then.
 */
final readonly class ChunkableSection
{
    public function __construct(
        public string $sectionId,
        public string $documentVersionId,
        public string $sourceDocumentId,
        public string $sourceCode,
        public TrustTier $trustTier,
        public ?string $issuer,
        public string $documentType,
        public SectionType $sectionType,
        public string $citationLabel,
        public ?DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public ?DateTimeImmutable $publicationDate,
        public ?int $pageStart,
        public ?int $pageEnd,
        public string $text,
    ) {
    }
}
