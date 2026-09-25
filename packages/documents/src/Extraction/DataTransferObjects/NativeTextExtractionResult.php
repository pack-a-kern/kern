<?php

declare(strict_types=1);

namespace Kern\Documents\Extraction\DataTransferObjects;

/**
 * Mirrors `apps/processing`'s `processing.documents.DocumentExtractionResult`
 * (`docs/handoff/019-pdf-text-extraction.md`, reconciled against the real endpoint in
 * `docs/handoff/024-extraction-endpoint.md`).
 *
 * `pages` holds each page's result, keyed by 1-indexed page number — the Laravel-side analogue of
 * Python's `UntrustedDocumentText`: nothing in this module ever concatenates a page's content
 * into a broadcast payload, a log line, or a prompt without treating it as untrusted data
 * (`BLUEPRINT.md` §14.5). `needsOcr` is the document-level routing decision (`overall_decision ===
 * ocr_required` on the wire) — the one field this module currently acts on; see
 * `PageExtractionResult`'s own docblock for why per-page `quality_score` is kept without being
 * consumed downstream yet.
 */
final readonly class NativeTextExtractionResult
{
    /** @param array<int, PageExtractionResult> $pages 1-indexed page number => page result. */
    public function __construct(
        public int $pageCount,
        public bool $needsOcr,
        public array $pages,
    ) {
    }

    public function joinedText(): UntrustedDocumentText
    {
        return UntrustedDocumentText::join(array_values(array_map(
            static fn (PageExtractionResult $page): UntrustedDocumentText => $page->text,
            $this->pages,
        )));
    }
}
