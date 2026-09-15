<?php

declare(strict_types=1);

namespace App\Domain\Documents\DataTransferObjects;

/**
 * One file on its way into a document, before anything has been checked about it.
 *
 * A pair rather than a framework `UploadedFile`, so the upload action can be driven by an importer
 * or an SPV fetch — neither of which has an HTTP request behind it — without either pretending to
 * be one.
 *
 * `declaredFilename` is the name the client sent, and is treated as untrusted throughout: it is
 * sanitised by `App\Domain\Documents\FileSecurity\FilenameSanitizer`, never used to decide the
 * file's type, and never used to build an object key.
 */
final readonly class IncomingFile
{
    public function __construct(
        public string $tempPath,
        public string $declaredFilename,
    ) {
    }
}
