<?php

declare(strict_types=1);

namespace App\Domain\Documents\Extraction\DataTransferObjects;

/**
 * One page's native-text-extraction result, mirroring `apps/processing`'s
 * `PageExtractionResult`/per-page `quality_score` (`docs/handoff/019-pdf-text-extraction.md`).
 *
 * `qualityScoreDecimal` lives here, per page, because the real endpoint has no single
 * document-level quality score — `docs/handoff/024-extraction-endpoint.md`'s "Differences from
 * handoff 022's assumed contract" #2. Nothing in this module consumes the value yet; it is kept
 * (rather than discarded during parsing) because it is the actual per-page signal the real
 * contract sends and a future human-review UI (`BLUEPRINT.md` §14.6) is the documented reason to want
 * it (`docs/handoff/024`'s open question #3) — discarding a field the wire actually sends, only to
 * reintroduce it later, would not be simpler than keeping it typed here now.
 */
final readonly class PageExtractionResult
{
    public function __construct(
        public int $pageNumber,
        public string $qualityScoreDecimal,
        public UntrustedDocumentText $text,
    ) {
    }
}
