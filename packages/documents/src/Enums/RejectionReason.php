<?php

declare(strict_types=1);

namespace Kern\Documents\Enums;

/**
 * `documents.rejection_reason` — why a document reached `DocumentStatus::REJECTED`. Deliberately
 * a closed, PII-free enum (never a free-text message): this column is safe to log and to
 * broadcast (`BLUEPRINT.md` §16), unlike, say, a virus signature name or a raw validation message
 * that might echo back attacker-controlled filename content.
 */
enum RejectionReason: string
{
    case INFECTED = 'INFECTED';
    case INVALID_MAGIC_BYTES = 'INVALID_MAGIC_BYTES';
    case EXTENSION_MISMATCH = 'EXTENSION_MISMATCH';
    case FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    case IMAGE_PIXEL_LIMIT_EXCEEDED = 'IMAGE_PIXEL_LIMIT_EXCEEDED';
    case UNSUPPORTED_FILE_TYPE = 'UNSUPPORTED_FILE_TYPE';

    // The text-extraction service's closed set of "this document is bad" outcomes
    // (docs/handoff/024-extraction-endpoint.md), mapped 1:1 by
    // Kern\Documents\Extraction\HttpTextExtractionGateway. `file_too_large` and
    // `invalid_magic_bytes` reuse the cases above rather than duplicating them — the same
    // rejection concept, discovered later in the pipeline. There is deliberately no `BOMB`/
    // decompression-bomb case: the service itself has no such distinct outcome, it surfaces as
    // EXTRACTION_TIMEOUT or MALFORMED_PDF depending on which of its two defences catches it first
    // (handoff 024's "Differences" #5) — inventing one here would misrepresent it.
    case ENCRYPTED_PDF = 'ENCRYPTED_PDF';
    case TOO_MANY_PAGES = 'TOO_MANY_PAGES';
    case EMPTY_DOCUMENT = 'EMPTY_DOCUMENT';
    case MALFORMED_PDF = 'MALFORMED_PDF';
    case EXTRACTION_TIMEOUT = 'EXTRACTION_TIMEOUT';
    case EXTRACTION_FAILED = 'EXTRACTION_FAILED';
}
