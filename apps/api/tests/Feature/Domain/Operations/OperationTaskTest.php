<?php

declare(strict_types=1);

use App\Domain\Operations\DataTransferObjects\TaskArgument;
use App\Domain\Operations\Enums\OperationTask;
use Illuminate\Support\Facades\Artisan;

it('names only commands this application actually registers', function (): void {
    // The enum is the allowlist. A case naming a command that does not exist would fail at run
    // time, on a queue worker, minutes after the operator clicked — with the failure surfacing as
    // a red row rather than as a refused request.
    $registered = array_keys(Artisan::all());

    foreach (OperationTask::cases() as $task) {
        expect($registered)->toContain($task->command());
    }
});

it('constrains every task argument — no free text reaches a command', function (): void {
    // The invariant is not "integers only" (an ingest needs a document type) but that every
    // argument is *bounded*: a numeric range, a closed set of values, or a calendar date.
    foreach (OperationTask::cases() as $task) {
        foreach ($task->arguments() as $argument) {
            expect([TaskArgument::TYPE_INTEGER, TaskArgument::TYPE_CHOICE, TaskArgument::TYPE_DATE])
                ->toContain($argument->type);

            if ($argument->type === TaskArgument::TYPE_INTEGER) {
                expect($argument->min)->toBeInt()
                    ->and($argument->max)->toBeInt()
                    ->and($argument->min ?? 0)->toBeLessThanOrEqual($argument->max ?? 0);
            }

            if ($argument->type === TaskArgument::TYPE_CHOICE) {
                expect($argument->options)->not->toBeEmpty();
            }
        }
    }
});

it('marks exactly the tasks that write nothing as read-only', function (): void {
    expect(OperationTask::AUDIT_VERIFY_CHAINS->isReadOnly())->toBeTrue()
        ->and(OperationTask::FX_IMPORT_DAILY->isReadOnly())->toBeFalse()
        ->and(OperationTask::LEGISLATION_INGEST->isReadOnly())->toBeFalse()
        ->and(OperationTask::FX_BACKFILL_YEAR->isReadOnly())->toBeFalse();
});
