<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Enums;

/**
 * `task_runs.trigger` — what started this run. Both land in the same ledger: "did the nightly
 * import work" and "did my manual retry work" are the same question, and separating them into two
 * tables would mean answering it twice.
 */
enum TaskRunTrigger: string
{
    case SCHEDULED = 'SCHEDULED';
    case MANUAL = 'MANUAL';
}
