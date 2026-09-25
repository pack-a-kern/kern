<?php

declare(strict_types=1);

namespace Kern\Documents\DataTransferObjects;

use Kern\Documents\FileSecurity\SniffedFileType;

/**
 * The outcome of `Kern\Documents\FileSecurity\FileSecurityValidator::validate()` — every
 * fact about the upload that was derived from the file's own bytes, never from anything the
 * client declared.
 */
final readonly class ValidatedUpload
{
    public function __construct(
        public SniffedFileType $sniffedType,
        public string $sanitizedFilename,
        public int $sizeBytes,
        public string $sha256,
    ) {
    }
}
