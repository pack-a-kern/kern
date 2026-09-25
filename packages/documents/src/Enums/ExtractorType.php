<?php

declare(strict_types=1);

namespace Kern\Documents\Enums;

/**
 * `document_extractions.extractor_type` — which pipeline stage produced this row.
 * `NATIVE_PDF_TEXT` never leaves the local environment (ADR 0005 decision #1);
 * `EXTERNAL_DOCUMENT_AI` is the consent-gated external send (ADR 0005 decisions #2-#3),
 * `Kern\Documents\ExternalAi\RequestExternalDocumentExtraction`. `HUMAN_CORRECTION` is the
 * `BLUEPRINT.md` §14.6 human-review outcome: `Kern\Documents\Review\
 * CorrectDocumentExtraction` writes a brand-new row of this type rather than ever overwriting a
 * `PROPOSED` row's own `result_ciphertext` in place — the original stays exactly as the model
 * produced it, kept alongside the correction.
 */
enum ExtractorType: string
{
    case NATIVE_PDF_TEXT = 'NATIVE_PDF_TEXT';
    case EXTERNAL_DOCUMENT_AI = 'EXTERNAL_DOCUMENT_AI';
    case HUMAN_CORRECTION = 'HUMAN_CORRECTION';

    /**
     * A deterministic parser built for one document shape — a bank's statement layout, a receipt's
     * fiscal footer.
     *
     * Kept apart from `EXTERNAL_DOCUMENT_AI` because the two differ in the way that matters: a
     * parser's output is reproducible from the same bytes forever, and a model's is not. When a
     * figure has to be defended years later, "this pattern matched this text" is an argument;
     * "a model said so in 2026" is not.
     *
     * For the layouts it recognises a parser is also simply more accurate than a model, and needs
     * no consent, no network call and no provider — which is why the pipeline tries parsers first.
     */
    case PATTERN_PARSER = 'PATTERN_PARSER';
}
