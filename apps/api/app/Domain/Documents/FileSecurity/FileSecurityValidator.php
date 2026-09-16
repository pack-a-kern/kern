<?php

declare(strict_types=1);

namespace App\Domain\Documents\FileSecurity;

use App\Domain\Documents\DataTransferObjects\ValidatedUpload;
use App\Domain\Documents\FileSecurity\Exceptions\ExtensionMismatchException;
use App\Domain\Documents\FileSecurity\Exceptions\FileTooLargeException;
use App\Domain\Documents\FileSecurity\Exceptions\ImagePixelLimitExceededException;
use App\Domain\Documents\FileSecurity\Exceptions\UnsupportedFileTypeException;
use RuntimeException;

/**
 * `BLUEPRINT.md` §14.2 file security — the controls that must hold **before** any byte is written to
 * quarantine storage or forwarded anywhere (ClamAV, the text-extraction service): size limits per
 * type, magic-byte validation (never the client-supplied MIME/extension), a declared-extension-
 * vs-real-content check, and an image pixel-count ceiling. Operates on a local temp file path
 * (Laravel's own upload handling already streams the request body there) so it never loads a
 * whole large document into PHP memory just to validate it — only a bounded leading window is
 * read for sniffing, and `hash_file()`/`getimagesize()` both stream/read-header rather than
 * slurping the file.
 *
 * Deliberately takes its limits as plain constructor scalars, not `config()` calls — this class
 * has no HTTP/Eloquent/clock dependency and no reason to reach for a facade
 * (`docs/CODE-QUALITY.md` §4 "no facades or service locators inside domain core"); the
 * `DocumentsServiceProvider` reads `config/documents.php` once and injects the values.
 */
final readonly class FileSecurityValidator
{
    public function __construct(
        private FilenameSanitizer $filenameSanitizer,
        private int $maxPdfBytes,
        private int $maxImageBytes,
        private int $maxTextBytes,
        private int $maxImagePixels,
    ) {
    }

    public function validate(string $tempFilePath, string $declaredFilename): ValidatedUpload
    {
        $sizeBytes = filesize($tempFilePath);

        if ($sizeBytes === false) {
            throw new RuntimeException("Unable to read the size of the uploaded temp file at \"{$tempFilePath}\".");
        }

        $sniffedType = SniffedFileType::sniff($this->readLeadingBytes($tempFilePath));

        if ($sniffedType === null) {
            throw UnsupportedFileTypeException::unrecognized();
        }

        // Per type, because the reasons differ: a PDF is large because it is a document, an image
        // because it is a photograph, and a spreadsheet that is tens of megabytes of text is not a
        // statement anybody exported.
        $maxBytes = match ($sniffedType) {
            SniffedFileType::PDF => $this->maxPdfBytes,
            SniffedFileType::JPEG, SniffedFileType::PNG => $this->maxImageBytes,
            // A workbook is a data file like a CSV rather than a document or a photograph: it holds
            // the same rows, in a container. Same ceiling, for the same reason — a statement export
            // of tens of megabytes is not a statement anybody's bank produced.
            SniffedFileType::CSV, SniffedFileType::XLSX, SniffedFileType::XLS => $this->maxTextBytes,
        };

        if ($sizeBytes > $maxBytes) {
            throw FileTooLargeException::exceeds($maxBytes);
        }

        $this->assertExtensionMatches($declaredFilename, $sniffedType);

        if ($sniffedType->isImage()) {
            $this->assertPixelLimit($tempFilePath);
        }

        $sha256 = hash_file('sha256', $tempFilePath);

        if ($sha256 === false) {
            throw new RuntimeException("Unable to hash the uploaded temp file at \"{$tempFilePath}\".");
        }

        return new ValidatedUpload(
            sniffedType: $sniffedType,
            sanitizedFilename: $this->filenameSanitizer->sanitize($declaredFilename),
            sizeBytes: $sizeBytes,
            sha256: $sha256,
        );
    }

    private function readLeadingBytes(string $tempFilePath): string
    {
        $handle = fopen($tempFilePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the uploaded temp file at \"{$tempFilePath}\".");
        }

        try {
            $bytes = fread($handle, SniffedFileType::SNIFF_WINDOW_BYTES);

            return $bytes === false ? '' : $bytes;
        } finally {
            fclose($handle);
        }
    }

    private function assertExtensionMatches(string $declaredFilename, SniffedFileType $sniffedType): void
    {
        $extension = strtolower(pathinfo($declaredFilename, PATHINFO_EXTENSION));

        if (! in_array($extension, $sniffedType->allowedExtensions(), true)) {
            throw ExtensionMismatchException::forSniffedType($sniffedType);
        }
    }

    private function assertPixelLimit(string $tempFilePath): void
    {
        // getimagesize() reads only the image header, never decodes the full raster — safe
        // against a decompression-bomb-shaped file even before this pixel check itself runs.
        $dimensions = @getimagesize($tempFilePath);

        if ($dimensions === false) {
            throw UnsupportedFileTypeException::unrecognized();
        }

        [$width, $height] = $dimensions;

        if ($width * $height > $this->maxImagePixels) {
            throw ImagePixelLimitExceededException::exceeds($this->maxImagePixels);
        }
    }
}
