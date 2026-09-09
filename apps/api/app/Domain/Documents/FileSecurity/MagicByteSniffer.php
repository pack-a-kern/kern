<?php

declare(strict_types=1);

namespace App\Domain\Documents\FileSecurity;

/**
 * Identifies a file's real type from its own leading bytes — `BLUEPRINT.md` §14.2's "ignore the
 * supplied MIME" for the three types this pipeline accepts. Never trusts a caller-declared
 * extension or MIME type; a caller-declared extension is checked for agreement *afterwards*, by
 * `FileSecurityValidator`, not used here.
 *
 * PDF is matched with a bounded scan window rather than an offset-0 check — the same defensive
 * posture `apps/processing`'s own PDF text-extraction service documents
 * (`docs/handoff/019-pdf-text-extraction.md` "Magic-byte validation is a bounded scan for
 * `%PDF-`, not an offset-0 check"): ISO 32000-2 §7.5.2 permits leading bytes before the header
 * some generators prepend. JPEG/PNG headers are always at offset 0 per their own specifications —
 * no scan window applies to them.
 */
final class MagicByteSniffer
{
    private const int PDF_SCAN_WINDOW_BYTES = 1024;

    private const string PDF_SIGNATURE = '%PDF-';

    private const string JPEG_SIGNATURE = "\xFF\xD8\xFF";

    private const string PNG_SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";

    /**
     * @param string $leadingBytes at least the first {@see self::PDF_SCAN_WINDOW_BYTES} bytes of
     *                             the file, or the whole file if it is shorter.
     */
    public function sniff(string $leadingBytes): ?SniffedFileType
    {
        if (str_starts_with($leadingBytes, self::JPEG_SIGNATURE)) {
            return SniffedFileType::JPEG;
        }

        if (str_starts_with($leadingBytes, self::PNG_SIGNATURE)) {
            return SniffedFileType::PNG;
        }

        if (str_contains(substr($leadingBytes, 0, self::PDF_SCAN_WINDOW_BYTES), self::PDF_SIGNATURE)) {
            return SniffedFileType::PDF;
        }

        return null;
    }
}
