<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Enums;

/** `task_runs.status`. A run starts RUNNING and reaches exactly one terminal state. */
enum TaskRunStatus: string
{
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
}
