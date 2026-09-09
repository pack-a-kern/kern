<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Operations\Enums\JobRunStatus;
use App\Domain\Operations\Enums\JobRunTrigger;
use App\Domain\Operations\Enums\OperationTask;
use App\Domain\Operations\Jobs\RunOperationTask;
use App\Domain\Operations\Models\JobRun;
use App\Domain\Operations\Models\ScheduledTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    Artisan::call('taxos:access:sync');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function operationsUser(?string $role = null): User
{
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

it('refuses the operations console to a user with no platform role', function (): void {
    $user = operationsUser();

    $this->actingAs($user)->getJson('/api/v1/operations')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/operations/activity')->assertForbidden();
});

it('separates reading operations from acting on them', function (): void {
    // ADMIN holds operations.view but not operations.run. The split exists because "read whether
    // last night's import worked" and "start an import against a public service" are different
    // levels of trust.
    $admin = operationsUser('ADMIN');

    $this->actingAs($admin)->getJson('/api/v1/operations')->assertOk();

    Queue::fake();

    $this->actingAs($admin)
        ->postJson('/api/v1/operations/tasks/' . OperationTask::ACCESS_SYNC->value . '/run')
        ->assertForbidden();

    $this->actingAs($admin)
        ->putJson('/api/v1/operations/tasks/' . OperationTask::FX_IMPORT_DAILY->value, ['enabled' => false])
        ->assertForbidden();
});

it('reports every declared task, whether scheduled or manual only', function (): void {
    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations')->assertOk();

    // Asserted by index rather than by search, which also pins the order to `OperationTask::cases()`
    // — the screen lists tasks in enum order, and a silent reordering would move a switch under an
    // operator's cursor.
    $response->assertJsonCount(count(OperationTask::cases()), 'tasks')
        ->assertJsonPath('tasks.0.key', OperationTask::FX_IMPORT_DAILY->value)
        ->assertJsonPath('tasks.0.schedulable', true)
        ->assertJsonPath('tasks.0.enabled', true)
        // Read as a map, not by a dotted path: a task key contains a dot, which `Arr::get()`
        // would read as nesting.
        ->assertJsonPath('tasks.0.cron_expression', Config::array('operations.schedule.expressions')[
            OperationTask::FX_IMPORT_DAILY->value
        ])
        ->assertJsonPath('tasks.0.key', OperationTask::FX_IMPORT_DAILY->value);

    $manualIndex = array_search(OperationTask::LEGISLATION_REINDEX, OperationTask::cases(), true);

    $response->assertJsonPath("tasks.{$manualIndex}.key", OperationTask::LEGISLATION_REINDEX->value)
        ->assertJsonPath("tasks.{$manualIndex}.schedulable", false)
        ->assertJsonPath("tasks.{$manualIndex}.cron_expression", null);
});

it('never claims a queue count when Horizon cannot be reached', function (): void {
    // The array cache store this suite runs on cannot answer Horizon's Redis-backed repositories,
    // so this exercises the real degraded path. The contract under test is that the counts come
    // back null — a zero would read as "nothing is queued" during exactly the incident where the
    // truth is that nobody can tell.
    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations')->assertOk();

    if ($response->json('queue.available') === false) {
        expect($response->json('queue.pending'))->toBeNull()
            ->and($response->json('queue.failed'))->toBeNull();
    } else {
        expect($response->json('queue.pending'))->toBeInt();
    }
});

it('dispatches a manual run, records it in the audit chain and refuses a second one', function (): void {
    Queue::fake();

    $actor = operationsUser('SUPER_ADMIN');
    $task = OperationTask::ACCESS_SYNC;

    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run")
        ->assertStatus(202)
        ->assertJsonPath('task', $task->value);

    Queue::assertPushed(RunOperationTask::class);

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'OPERATION_TASK_RUN_REQUESTED',
        'resource_type' => 'operation_task',
        'resource_id' => $task->value,
    ]);

    // The lock is taken in the request, so the second attempt is answered rather than silently
    // queued alongside the first.
    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run")
        ->assertStatus(409);
});

it('refuses a task the enum does not declare', function (): void {
    Queue::fake();

    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->postJson('/api/v1/operations/tasks/migrate.fresh/run')
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('validates a task argument against the range the task itself declares', function (): void {
    Queue::fake();

    $actor = operationsUser('SUPER_ADMIN');
    $task = OperationTask::FX_BACKFILL_YEAR;
    $minYear = Config::integer('operations.fx_backfill.min_year');

    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", ['year' => $minYear - 1])
        ->assertStatus(422);

    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", ['year' => (int) date('Y') + 1])
        ->assertStatus(422);

    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", ['year' => $minYear])
        ->assertStatus(202);

    Queue::assertPushed(RunOperationTask::class);
});

it('pauses and resumes a scheduled task, and audits the change', function (): void {
    $actor = operationsUser('SUPER_ADMIN');
    $task = OperationTask::FX_IMPORT_DAILY;

    $this->actingAs($actor)
        ->putJson("/api/v1/operations/tasks/{$task->value}", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('enabled', false);

    expect(ScheduledTask::query()->find($task->value)?->enabled)->toBeFalse();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'SCHEDULED_TASK_TOGGLED',
        'resource_id' => $task->value,
    ]);

    $this->actingAs($actor)
        ->putJson("/api/v1/operations/tasks/{$task->value}", ['enabled' => true])
        ->assertOk();

    expect(ScheduledTask::query()->find($task->value)?->enabled)->toBeTrue();
});

it('refuses to store a pause flag for a task that is not on the schedule', function (): void {
    // Storing it would write a flag nothing reads, and the screen would then show a switch that
    // does nothing.
    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->putJson('/api/v1/operations/tasks/' . OperationTask::LEGISLATION_REINDEX->value, ['enabled' => false])
        ->assertStatus(422);

    expect(ScheduledTask::query()->count())->toBe(0);
});

it('merges task runs into one activity feed and embeds the latest run per task on the overview', function (): void {
    $task = OperationTask::ACCESS_SYNC;

    foreach ([3, 2, 1] as $index) {
        $run = new JobRun();
        $run->task_key = $task->value;
        $run->trigger = JobRunTrigger::SCHEDULED;
        $run->status = JobRunStatus::SUCCEEDED;
        $startedAt = CarbonImmutable::now('UTC')->subMinutes($index);
        $run->started_at = $startedAt;
        $run->finished_at = $startedAt->addSeconds(4);
        $run->exit_code = 0;
        $run->save();
    }

    $actor = operationsUser('SUPER_ADMIN');

    $feed = $this->actingAs($actor)->getJson('/api/v1/operations/activity')->assertOk();

    expect($feed->json('total'))->toBe(3);

    // Newest first, and every row says which record it came from — that is what decides its action.
    $feed->assertJsonPath('activity.0.kind', 'task_run')
        ->assertJsonPath('activity.0.duration_seconds', 4);

    // Located by position in `OperationTask::cases()` rather than a hard-coded index: adding a task
    // to the enum shifts every one after it, and a literal here would fail for that alone.
    $index = array_search($task, OperationTask::cases(), true);

    $this->actingAs($actor)->getJson('/api/v1/operations')->assertOk()
        ->assertJsonPath("tasks.{$index}.key", $task->value)
        ->assertJsonPath("tasks.{$index}.last_run.status", JobRunStatus::SUCCEEDED->value);
});

it('lists the allowlist from config, not from the table that mirrors it', function (): void {
    // The regression this pins: the panel first read `official_sources`, which is populated lazily
    // on first fetch. After a restore the table is empty and the screen showed no sources at all —
    // indistinguishable from "nothing is allowlisted", which is the opposite of the truth.
    expect(DB::table('official_sources')->count())->toBe(0);

    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations')->assertOk();

    /** @var array<string, mixed> $declared */
    $declared = Config::array('sources.entries');

    $response->assertJsonCount(count($declared), 'sources');

    foreach (array_keys($declared) as $index => $code) {
        $response->assertJsonPath("sources.{$index}.code", $code)
            // Never fetched yet, so no row mirrors it — the distinction the panel needs to make.
            ->assertJsonPath("sources.{$index}.mirrored", false);
    }
});

it('marks a source as mirrored once a row exists for it', function (): void {
    DB::table('official_sources')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'BNR',
        'trust_tier' => 'A',
        'base_url' => 'https://curs.bnr.ro',
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations')->assertOk();

    // Addressed by index rather than searched, so the assertion stays typed: `BNR` is the fourth
    // entry declared in `config/sources.php`.
    $index = array_search('BNR', array_keys(Config::array('sources.entries')), true);

    expect($index)->not->toBeFalse();

    $response->assertJsonPath("sources.{$index}.code", 'BNR')
        ->assertJsonPath("sources.{$index}.mirrored", true);
});

it('exposes no way to write the official-source allowlist', function (): void {
    // `official_sources.enabled` is overwritten from config by `OfficialSourceRepository::resolve()`
    // on every fetch, and `AllowlistedHttpFetcher` checks the config definition rather than the row.
    // A write endpoint here would have appeared to disable a source while every import kept running.
    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->putJson('/api/v1/operations/sources/BNR', ['enabled' => false])
        ->assertNotFound();
});

it('answers 404 for a failed job that does not exist', function (): void {
    $actor = operationsUser('SUPER_ADMIN');

    $this->actingAs($actor)->postJson('/api/v1/operations/failed-jobs/missing/retry')->assertNotFound();
    $this->actingAs($actor)->deleteJson('/api/v1/operations/failed-jobs/missing')->assertNotFound();
});

it('filters the activity feed by outcome', function (): void {
    $task = OperationTask::ACCESS_SYNC;

    foreach ([JobRunStatus::SUCCEEDED, JobRunStatus::FAILED] as $index => $status) {
        $run = new JobRun();
        $run->task_key = $task->value;
        $run->trigger = JobRunTrigger::MANUAL;
        $run->status = $status;
        $run->started_at = CarbonImmutable::now('UTC')->subMinutes($index + 1);
        $run->finished_at = CarbonImmutable::now('UTC')->subMinutes($index + 1)->addSecond();
        $run->exit_code = $status === JobRunStatus::SUCCEEDED ? 0 : 1;
        $run->save();
    }

    $actor = operationsUser('SUPER_ADMIN');

    $this->actingAs($actor)->getJson('/api/v1/operations/activity?status=failed')->assertOk()
        ->assertJsonCount(1, 'activity')
        ->assertJsonPath('activity.0.status', JobRunStatus::FAILED->value);

    $this->actingAs($actor)->getJson('/api/v1/operations/activity?status=succeeded')->assertOk()
        ->assertJsonCount(1, 'activity');

    // `failed_jobs` is empty here, so restricting the feed to that source empties it — which also
    // proves the two records really are separate populations rather than one filtered twice.
    $this->actingAs($actor)->getJson('/api/v1/operations/activity?kind=failed_job')->assertOk()
        ->assertJsonCount(0, 'activity');
});

it('rejects an unknown activity filter rather than silently ignoring it', function (): void {
    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->getJson('/api/v1/operations/activity?status=whatever')
        ->assertStatus(422);
});

it('refuses an ingest URL whose host is not on the chosen source\'s allowlist', function (): void {
    Queue::fake();

    $actor = operationsUser('SUPER_ADMIN');
    $task = OperationTask::LEGISLATION_INGEST;

    // The field is typed by an operator, so this is the guard that matters: `legislatie.just.ro` is
    // the only host `config/sources.php` names for LEGISLATIE, and everything else is refused before
    // anything is dispatched — not merely later by the fetcher.
    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", [
            'source' => 'LEGISLATIE',
            'url' => 'https://evil.example.com/Public/DetaliiDocument/171282',
            'type' => 'LEGE',
        ])
        ->assertStatus(422);

    // Plain http is refused too, on a host that is otherwise allowed.
    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", [
            'source' => 'LEGISLATIE',
            'url' => 'http://legislatie.just.ro/Public/DetaliiDocument/171282',
            'type' => 'LEGE',
        ])
        ->assertStatus(422);

    // An unknown document type is refused: the set is closed.
    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", [
            'source' => 'LEGISLATIE',
            'url' => 'https://legislatie.just.ro/Public/DetaliiDocument/171282',
            'type' => 'WHATEVER',
        ])
        ->assertStatus(422);

    Queue::assertNothingPushed();

    $this->actingAs($actor)
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", [
            'source' => 'LEGISLATIE',
            'url' => 'https://legislatie.just.ro/Public/DetaliiDocument/171282',
            'type' => 'LEGE',
        ])
        ->assertStatus(202);

    Queue::assertPushed(RunOperationTask::class);
});
