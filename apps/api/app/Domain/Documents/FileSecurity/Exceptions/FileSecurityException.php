<?php

declare(strict_types=1);

namespace App\Domain\Documents\FileSecurity\Exceptions;

use App\Domain\Documents\Enums\RejectionReason;
use RuntimeException;

/**
 * Base type for every reason `FileSecurityValidator` refuses an upload. Never constructed with
 * the raw filename or file bytes in its message — only a fixed, human-readable sentence and the
 * closed {@see RejectionReason} the caller needs to record.
 */
abstract class FileSecurityException extends RuntimeException
{
    abstract public function reason(): RejectionReason;
}
