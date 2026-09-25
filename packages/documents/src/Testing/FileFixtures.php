<?php

declare(strict_types=1);

namespace Kern\Documents\Testing;

/**
 * Minimal-but-real byte fixtures for the file-security checks, shipped with the package.
 *
 * In `src/Testing` rather than in a test directory because the tests that need it are on both sides
 * of the boundary: this package's own unit tests, and an application's upload tests. The same reason
 * `Kern\Platform\Testing` ships its doubles, and the same shape Laravel uses. Every fixture
 * here is genuinely parseable by the same PHP functions the production code uses
 * (`getimagesize()`, the `%PDF-`/JPEG/PNG magic-byte checks) — not a fake string that merely
 * looks right. PNG dimensions in particular are real, verified against `getimagesize()` directly
 * (`docs/handoff/022-document-pipeline.md`), not assumed from the PNG spec alone.
 */
final class FileFixtures
{
    public static function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    }

    /** A real, minimal, `getimagesize()`-parseable PNG: signature + IHDR chunk only. */
    public static function pngWithDimensions(int $width, int $height): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $ihdrData = pack('NN', $width, $height) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
        $ihdrCrc = crc32('IHDR' . $ihdrData);
        $ihdrChunk = pack('N', strlen($ihdrData)) . 'IHDR' . $ihdrData . pack('N', $ihdrCrc);

        return $signature . $ihdrChunk;
    }

    /** A real, minimal, `getimagesize()`-parseable baseline JPEG (SOI + SOF0 + EOI). */
    public static function jpegWithDimensions(int $width, int $height): string
    {
        $soi = "\xFF\xD8";
        $sof0Payload = chr(8) . pack('n', $height) . pack('n', $width) . chr(1) . chr(1) . chr(0x11) . chr(0);
        $sof0 = "\xFF\xC0" . pack('n', strlen($sof0Payload) + 2) . $sof0Payload;
        $eoi = "\xFF\xD9";

        return $soi . $sof0 . $eoi;
    }

    public static function eicarTestString(): string
    {
        return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    }
}
