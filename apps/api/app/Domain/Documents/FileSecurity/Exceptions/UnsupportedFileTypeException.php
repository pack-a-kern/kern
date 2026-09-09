<?php

declare(strict_types=1);

namespace App\Domain\Documents\FileSecurity\Exceptions;

use App\Domain\Documents\Enums\RejectionReason;

/** The file's own bytes do not match any accepted `SniffedFileType`. */
final class UnsupportedFileTypeException extends FileSecurityException
{
    public static function unrecognized(): self
    {
        return new self('The uploaded file is not a recognized PDF, JPEG, or PNG.');
    }

    public function reason(): RejectionReason
    {
        return RejectionReason::UNSUPPORTED_FILE_TYPE;
    }
}
