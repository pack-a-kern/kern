<?php

declare(strict_types=1);

namespace Kern\Documents\FileSecurity\Exceptions;

use Kern\Documents\Enums\RejectionReason;

/** The file exceeds the configured size limit for its (sniffed, or as-yet-unknown) type. */
final class FileTooLargeException extends FileSecurityException
{
    public static function exceeds(int $maxBytes): self
    {
        return new self(sprintf('The file exceeds the maximum allowed size of %d bytes.', $maxBytes));
    }

    public function reason(): RejectionReason
    {
        return RejectionReason::FILE_TOO_LARGE;
    }
}
