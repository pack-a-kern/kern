<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Enums\TaskRunStatus;
use App\Domain\Operations\Enums\TaskRunTrigger;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One execution of a background operation — see this table's migration docblock for why it exists
 * at all.
 *
 * Not scoped to a tax subject, and correctly so: every task here is installation-wide (an FX
 * import, a legislation reindex, a catalogue sync). There is no `ScopedToTaxSubject` to apply.
 *
 * `task_key` is cast to {@see OperationTask} only where a run's task is still declared. A run of a
 * task that has since been removed from the enum stays readable — {@see self::taskOrNull()} — so
 * the history survives a code change rather than throwing while rendering it.
 *
 * @property string $id
 * @property string $task_key
 * @property TaskRunTrigger $trigger
 * @property TaskRunStatus $status
 * @property string|null $triggered_by
 * @property array<string, int|string>|null $arguments
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 * @property int|null $exit_code
 * @property string|null $output
 */
final class TaskRun extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'task_runs';

    public $timestamps = false;

    protected $fillable = [
        'task_key',
        'trigger',
        'status',
        'triggered_by',
        'arguments',
        'started_at',
        'finished_at',
        'exit_code',
        'output',
    ];

    protected $casts = [
        'trigger' => TaskRunTrigger::class,
        'status' => TaskRunStatus::class,
        'arguments' => 'array',
        // Immutable, to match the `CarbonImmutable` every service in this module produces —
        // a mutable cast would make an assignment a silent type change.
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'exit_code' => 'integer',
    ];

    /** The task this run executed, or `null` if the enum no longer declares it. */
    public function taskOrNull(): ?OperationTask
    {
        return OperationTask::tryFrom($this->task_key);
    }

    /** Whole seconds the run took, or `null` while it is still running. */
    public function durationSeconds(): ?int
    {
        if ($this->finished_at === null) {
            return null;
        }

        return (int) $this->finished_at->diffInSeconds($this->started_at, absolute: true);
    }
}
