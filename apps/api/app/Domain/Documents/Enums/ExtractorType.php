<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * `document_extractions.extractor_type` — which pipeline stage produced this row.
 * `NATIVE_PDF_TEXT` never leaves the local environment (ADR 0005 decision #1);
 * `EXTERNAL_DOCUMENT_AI` is the consent-gated external send (ADR 0005 decisions #2-#3),
 * `App\Domain\Documents\ExternalAi\RequestExternalDocumentExtraction`. `HUMAN_CORRECTION` is the
 * `BLUEPRINT.md` §14.6 human-review outcome: `App\Domain\Documents\Review\
 * CorrectDocumentExtraction` writes a brand-new row of this type rather than ever overwriting a
 * `PROPOSED` row's own `result_ciphertext` in place — the original stays exactly as the model
 * produced it, kept alongside the correction.
 */
enum ExtractorType: string
{
    case NATIVE_PDF_TEXT = 'NATIVE_PDF_TEXT';
    case EXTERNAL_DOCUMENT_AI = 'EXTERNAL_DOCUMENT_AI';
    case HUMAN_CORRECTION = 'HUMAN_CORRECTION';
}
