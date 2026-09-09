<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Exceptions;

use RuntimeException;

final class SubmissionResponseAlreadyRecordedException extends RuntimeException
{
    public static function forSubmission(string $submissionId): self
    {
        return new self("Submission {$submissionId} already has a recorded response.");
    }
}
