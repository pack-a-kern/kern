<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\DataTransferObjects;

use Kern\TaxRomania\Operations\Enums\OperationTask;

/**
 * One task as the scheduler and the administration screen both see it: the code-declared identity
 * ({@see OperationTask}), the configured timing, and the one runtime-mutable bit — whether it is
 * enabled.
 *
 * `cronExpression` is `null` for a manual-only task. That is not a missing value: a task with no
 * schedule is still runnable on demand, and the screen lists it alongside the scheduled ones
 * rather than hiding it, because "what can this installation do in the background" is one list.
 */
final readonly class ScheduledTaskDefinition
{
    public function __construct(
        public OperationTask $task,
        public ?string $cronExpression,
        public string $timezone,
        public bool $enabled,
    ) {
    }

    /** A task the scheduler registers: declared schedulable in code *and* configured with a timing. */
    public function isScheduled(): bool
    {
        return $this->task->isScheduled() && $this->cronExpression !== null;
    }
}
