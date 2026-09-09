<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

/** `job_runs.status`. A run starts RUNNING and reaches exactly one terminal state. */
enum JobRunStatus: string
{
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return $this !== self::RUNNING;
    }
}
