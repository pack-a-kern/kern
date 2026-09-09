<?php

declare(strict_types=1);

namespace App\Domain\Documents\FileSecurity;

/**
 * The closed set of file types this pipeline accepts, identified from the file's own bytes
 * (`BLUEPRINT.md` §14.2 "ignore supplied MIME") — never from a client-supplied `Content-Type` header
 * or filename extension. See `MagicByteSniffer` for how each case is detected.
 */
enum SniffedFileType: string
{
    case PDF = 'application/pdf';
    case JPEG = 'image/jpeg';
    case PNG = 'image/png';

    /** @return list<string> lower-case extensions a declared filename may legitimately carry. */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::PDF => ['pdf'],
            self::JPEG => ['jpg', 'jpeg'],
            self::PNG => ['png'],
        };
    }

    public function isImage(): bool
    {
        return $this === self::JPEG || $this === self::PNG;
    }
}
