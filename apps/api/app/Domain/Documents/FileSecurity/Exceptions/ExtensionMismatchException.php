<?php

declare(strict_types=1);

namespace App\Domain\Documents\FileSecurity\Exceptions;

use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\FileSecurity\SniffedFileType;

/** The declared filename's extension does not agree with the file's sniffed content type. */
final class ExtensionMismatchException extends FileSecurityException
{
    public static function forSniffedType(SniffedFileType $sniffed): self
    {
        return new self(sprintf(
            'The file extension does not match its content — the file is actually %s.',
            $sniffed->value,
        ));
    }

    public function reason(): RejectionReason
    {
        return RejectionReason::EXTENSION_MISMATCH;
    }
}
