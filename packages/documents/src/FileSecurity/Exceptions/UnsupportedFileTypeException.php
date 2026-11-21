<?php

declare(strict_types=1);

namespace Kern\Documents\FileSecurity\Exceptions;

use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\FileSecurity\SniffedFileType;

/** The file's own bytes do not match any accepted `SniffedFileType`. */
final class UnsupportedFileTypeException extends FileSecurityException
{
    public static function unrecognized(): self
    {
        // The accepted set is read off the enum, so this never again names a subset of what the
        // pipeline actually takes (it used to say only "PDF, JPEG, or PNG" after CSV and the
        // spreadsheet formats were added).
        return new self('The uploaded file is not a recognized file type. Accepted: ' . SniffedFileType::humanList() . '.');
    }

    public function reason(): RejectionReason
    {
        return RejectionReason::UNSUPPORTED_FILE_TYPE;
    }
}
