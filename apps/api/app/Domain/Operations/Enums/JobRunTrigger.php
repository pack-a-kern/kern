<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

/**
 * `job_runs.trigger` — what started this run. Both land in the same ledger: "did the nightly
 * import work" and "did my manual retry work" are the same question, and separating them into two
 * tables would mean answering it twice.
 */
enum JobRunTrigger: string
{
    case SCHEDULED = 'SCHEDULED';
    case MANUAL = 'MANUAL';
}
