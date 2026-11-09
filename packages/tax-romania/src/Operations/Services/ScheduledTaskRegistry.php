<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\Operations\DataTransferObjects\ScheduledTaskDefinition;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Models\ScheduledTask;
use Throwable;

/**
 * Resolves every operation task into the form both the scheduler and the administration screen
 * need: code-declared identity, configured timing, database-held on/off switch.
 *
 * The three sources are separated on purpose, and the separation is the security property:
 *
 *  - *which* tasks exist — {@see OperationTask}, a closed enum, so no database row can ever name a
 *    command to execute;
 *  - *when* they run — `config/operations.php`, env-overridable, so retiming is an operator action
 *    and not a code change, but still not a text field on a web form;
 *  - *whether* they run — `scheduled_tasks.enabled`, the one runtime-mutable bit.
 *
 * {@see self::schedulable()} is called by `bootstrap/app.php` on **every scheduler tick** — the
 * `scheduler` container runs `schedule:work`, which spawns a fresh `schedule:run` each minute, so
 * the closure re-evaluates and a toggle takes effect within a minute with no container restart.
 * That is also why it must never throw: if the database is briefly unreachable, the schedule
 * closure would abort and *nothing* would run, including the heartbeat that reports the scheduler
 * healthy. It therefore falls back to the code-declared defaults and reports the failure, rather
 * than taking the whole scheduler down with the database.
 */
final readonly class ScheduledTaskRegistry
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * Every task, scheduled or manual-only, with its current state.
     *
     * @return list<ScheduledTaskDefinition>
     */
    public function all(): array
    {
        $enabled = $this->enabledFlags();

        return array_map(
            fn (OperationTask $task): ScheduledTaskDefinition => new ScheduledTaskDefinition(
                task: $task,
                cronExpression: $this->cronExpressionFor($task),
                timezone: $this->timezone(),
                enabled: $enabled[$task->value] ?? true,
            ),
            OperationTask::cases(),
        );
    }

    /**
     * The subset the scheduler should register right now — declared schedulable, configured with a
     * timing, and not paused.
     *
     * @return list<ScheduledTaskDefinition>
     */
    public function schedulable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ScheduledTaskDefinition $definition): bool => $definition->isScheduled() && $definition->enabled,
        ));
    }

    public function find(OperationTask $task): ScheduledTaskDefinition
    {
        return new ScheduledTaskDefinition(
            task: $task,
            cronExpression: $this->cronExpressionFor($task),
            timezone: $this->timezone(),
            enabled: $this->enabledFlags()[$task->value] ?? true,
        );
    }

    /** Turn a task on or off. Takes effect on the next scheduler tick, within a minute. */
    public function setEnabled(OperationTask $task, bool $enabled, ?string $actorId): void
    {
        ScheduledTask::query()->updateOrCreate(
            ['key' => $task->value],
            ['enabled' => $enabled, 'updated_by' => $actorId],
        );
    }

    /**
     * @return array<string, bool>
     */
    private function enabledFlags(): array
    {
        try {
            /** @var array<string, bool> $flags */
            $flags = ScheduledTask::query()->pluck('enabled', 'key')->all();

            return $flags;
        } catch (Throwable $exception) {
            // See the class docblock: a scheduler that dies because the database blinked is worse
            // than one that keeps running its code-declared defaults for a minute.
            report($exception);

            return [];
        }
    }

    /**
     * The whole map is fetched and then indexed, rather than asking for
     * `operations.schedule.expressions.fx.import_daily` directly: a task key contains a dot, and
     * `Arr::get()` would read that dot as nesting and resolve nothing — silently returning `null`,
     * which here means "not scheduled". A task quietly dropping off the schedule is exactly the
     * failure this module exists to make visible, so it must not be possible to cause it with a
     * config lookup.
     */
    private function cronExpressionFor(OperationTask $task): ?string
    {
        if (! $task->isScheduled()) {
            return null;
        }

        $expressions = $this->config->get('operations.schedule.expressions');

        if (! is_array($expressions)) {
            return null;
        }

        $expression = $expressions[$task->value] ?? null;

        return is_string($expression) && $expression !== '' ? $expression : null;
    }

    private function timezone(): string
    {
        $timezone = $this->config->get('operations.schedule.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
