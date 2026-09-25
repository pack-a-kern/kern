<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Query;

use DateTimeImmutable;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Enums\TrustTier;

/**
 * One retrieved chunk, carrying the full citation chain `BLUEPRINT.md` §15.4 requires — document
 * version, section, page (where the source has one), and effective dates (or an explicit
 * {@see TemporalConfidence::DATE_UNKNOWN} when they are not known) — alongside the retrieval
 * scores that ranked it. This DTO **is** the citation; nothing downstream should need to
 * reconstruct one from a bare chunk id.
 *
 * `text` is retrieval context, not a fiscal figure (`BLUEPRINT.md` §15.5) — nothing on this class
 * computes anything; it only carries what was found and why it ranked where it did.
 */
final readonly class RetrievalResult
{
    public function __construct(
        public string $chunkId,
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
        public int $chunkIndex,
        public int $chunkCount,
        public string $text,
        public TemporalConfidence $temporalStatus,
        public float $lexicalRank,
        public ?float $semanticSimilarity,
        public float $citationSimilarity,
        public float $combinedScore,
    ) {
    }
}
