<?php

declare(strict_types=1);

namespace App\Domain\Spv\Enums;

/** `spv_sync_runs.status` (`BLUEPRINT.md` §6.11). */
enum SpvSyncRunStatus: string
{
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
}
