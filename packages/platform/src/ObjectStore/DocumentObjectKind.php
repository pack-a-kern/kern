<?php

declare(strict_types=1);

namespace Kern\Platform\ObjectStore;

/**
 * The closed set of document-derivative kinds an object key may name (BLUEPRINT.md §6.6
 * `document_derivatives.kind`: THUMBNAIL|PDF_NORMALIZED|OCR|EXTRACTED_TEXT|STRUCTURED_JSON, plus
 * the original upload itself and a preview, both named explicitly in §4.6's own examples).
 *
 * `ObjectKey` only ever accepts a value from this enum for the last path segment — never a
 * caller-supplied string — which is what makes "an object key cannot contain PII" true by
 * construction rather than by convention.
 */
enum DocumentObjectKind: string
{
    case ORIGINAL = 'original';
    case PREVIEW = 'preview';
    case THUMBNAIL = 'thumbnail';
    case PDF_NORMALIZED = 'pdf_normalized';
    case OCR = 'ocr';
    case EXTRACTED_TEXT = 'extracted_text';
    case STRUCTURED_JSON = 'structured_json';

    /** A statement PDF as the provider converted it to CSV — what the statement import reads. */
    case CONVERTED_CSV = 'converted_csv';

    /**
     * The on-disk filename for this kind. `OCR` is `ocr.json.enc`, matching BLUEPRINT.md §4.6's
     * exact example; every other kind is `{value}.enc`.
     */
    public function filename(): string
    {
        return match ($this) {
            self::OCR => 'ocr.json.enc',
            default => "{$this->value}.enc",
        };
    }
}
