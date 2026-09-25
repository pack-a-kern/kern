<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Operations\Enums\OperationTask;
use Kern\TaxRomania\Operations\Enums\TaskRunStatus;
use Kern\TaxRomania\Operations\Enums\TaskRunTrigger;
use Kern\TaxRomania\Operations\Jobs\RunOperationTask;
use Kern\TaxRomania\Operations\Models\ScheduledTask;
use Kern\TaxRomania\Operations\Models\TaskRun;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    Artisan::call('kern:access:sync');
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
    $this->actingAs($user)->getJson('/api/v1/operations/jobs')->assertForbidden();
    $this->actingAs($user)
        ->getJson('/api/v1/operations/tasks/' . OperationTask::ACCESS_SYNC->value . '/runs')
        ->assertForbidden();
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

it('reports every listed task, whether scheduled or manual only', function (): void {
    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations')->assertOk();
    $listed = array_values(array_filter(OperationTask::cases(), static fn (OperationTask $task): bool => $task->isListed()));

    /** @var list<array<string, mixed>> $tasks */
    $tasks = $response->json('tasks');

    // Reading a law is started from the legislation page, never from here.
    expect(array_column($tasks, 'key'))->not->toContain(OperationTask::LEGISLATION_INGEST->value);

    // Asserted by index rather than by search, which also pins the order to `OperationTask::cases()`
    // — the screen lists tasks in enum order, and a silent reordering would move a switch under an
    // operator's cursor.
    $response->assertJsonCount(count($listed), 'tasks')
        ->assertJsonPath('tasks.0.key', OperationTask::FX_IMPORT_DAILY->value)
        ->assertJsonPath('tasks.0.schedulable', true)
        ->assertJsonPath('tasks.0.enabled', true)
        // Read as a map, not by a dotted path: a task key contains a dot, which `Arr::get()`
        // would read as nesting.
        ->assertJsonPath('tasks.0.cron_expression', Config::array('operations.schedule.expressions')[
            OperationTask::FX_IMPORT_DAILY->value
        ])
        ->assertJsonPath('tasks.0.key', OperationTask::FX_IMPORT_DAILY->value);

    $manualIndex = array_search(OperationTask::LEGISLATION_REINDEX, $listed, true);

    $response->assertJsonPath("tasks.{$manualIndex}.key", OperationTask::LEGISLATION_REINDEX->value)
        ->assertJsonPath("tasks.{$manualIndex}.schedulable", false)
        ->assertJsonPath("tasks.{$manualIndex}.cron_expression", null);
});

it('never claims a queue count when Horizon cannot be reached', function (): void {
    // The array cache store this suite runs on cannot answer Horizon's Redis-backed repositories,
    // so this exercises the real degraded path. The contract under test is that the counts come
    // back null — a zero would read as "nothing is queued" during exactly the incident where the
    // truth is that nobody can tell.
    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations/jobs')->assertOk();

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

it("lists one task's runs, newest first, and embeds the latest on the overview", function (): void {
    $task = OperationTask::ACCESS_SYNC;

    foreach ([3, 2, 1] as $index) {
        $run = new TaskRun();
        $run->task_key = $task->value;
        $run->trigger = TaskRunTrigger::SCHEDULED;
        $run->status = TaskRunStatus::SUCCEEDED;
        $startedAt = CarbonImmutable::now('UTC')->subMinutes($index);
        $run->started_at = $startedAt;
        $run->finished_at = $startedAt->addSeconds(4);
        $run->exit_code = 0;
        $run->save();
    }

    $actor = operationsUser('SUPER_ADMIN');

    $history = $this->actingAs($actor)
        ->getJson("/api/v1/operations/tasks/{$task->value}/runs")
        ->assertOk();

    expect($history->json('total'))->toBe(3);

    $history->assertJsonPath('runs.0.task_key', $task->value)
        ->assertJsonPath('runs.0.duration_seconds', 4);

    // Located by position among the listed `OperationTask::cases()` rather than a hard-coded index: adding a task
    // to the enum shifts every one after it, and a literal here would fail for that alone.
    $index = array_search($task, array_values(array_filter(OperationTask::cases(), static fn (OperationTask $listed): bool => $listed->isListed())), true);

    $this->actingAs($actor)->getJson('/api/v1/operations')->assertOk()
        ->assertJsonPath("tasks.{$index}.key", $task->value)
        ->assertJsonPath("tasks.{$index}.last_run.status", TaskRunStatus::SUCCEEDED->value);
});

it('pages a task history and keeps each task to its own', function (): void {
    foreach ([OperationTask::ACCESS_SYNC, OperationTask::AUDIT_VERIFY_CHAINS] as $task) {
        foreach (range(1, $task === OperationTask::ACCESS_SYNC ? 12 : 2) as $index) {
            $run = new TaskRun();
            $run->task_key = $task->value;
            $run->trigger = TaskRunTrigger::MANUAL;
            $run->status = TaskRunStatus::SUCCEEDED;
            $run->started_at = CarbonImmutable::now('UTC')->subMinutes($index);
            $run->save();
        }
    }

    $actor = operationsUser('SUPER_ADMIN');
    $key = OperationTask::ACCESS_SYNC->value;

    $this->actingAs($actor)->getJson("/api/v1/operations/tasks/{$key}/runs")->assertOk()
        ->assertJsonCount(10, 'runs')
        ->assertJsonPath('total', 12);

    $this->actingAs($actor)->getJson("/api/v1/operations/tasks/{$key}/runs?page=2")->assertOk()
        ->assertJsonCount(2, 'runs');

    // The other task's twelve neighbours are not in its history — the two never mix.
    $other = OperationTask::AUDIT_VERIFY_CHAINS->value;

    $this->actingAs($actor)->getJson("/api/v1/operations/tasks/{$other}/runs")->assertOk()
        ->assertJsonPath('total', 2);
});

it('answers 404 for a task key that is not in the enum', function (): void {
    // The set of tasks is closed in code, so an unknown key is a wrong address rather than a task
    // that happens to have never run.
    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->getJson('/api/v1/operations/tasks/not.a.task/runs')
        ->assertNotFound();
});

it('lists the allowlist from config, not from the table that mirrors it', function (): void {
    // The regression this pins: the panel first read `ext_official_sources`, which is populated lazily
    // on first fetch. After a restore the table is empty and the screen showed no sources at all —
    // indistinguishable from "nothing is allowlisted", which is the opposite of the truth.
    expect(DB::table('ext_official_sources')->count())->toBe(0);

    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/settings/platform-data')->assertOk();

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
    DB::table('ext_official_sources')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'BNR',
        'trust_tier' => 'A',
        'base_url' => 'https://curs.bnr.ro',
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->getJson('/api/v1/settings/platform-data')
        ->assertOk();

    // Addressed by index rather than searched, so the assertion stays typed: `BNR` is the fourth
    // entry declared in `config/sources.php`.
    $index = array_search('BNR', array_keys(Config::array('sources.entries')), true);

    expect($index)->not->toBeFalse();

    $response->assertJsonPath("sources.{$index}.code", 'BNR')
        ->assertJsonPath("sources.{$index}.mirrored", true);
});

it('exposes no way to write the official-source allowlist', function (): void {
    // `ext_official_sources.enabled` is overwritten from config by `OfficialSourceRepository::resolve()`
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

it('rejects a jobs status the screen does not offer rather than silently ignoring it', function (): void {
    // Each status reads a different Horizon list, so an unrecognised one is not a filter that
    // matches nothing — it names a list that does not exist.
    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->getJson('/api/v1/operations/jobs?status=whatever')
        ->assertStatus(422);
});

it('rejects a page size the pager does not offer', function (): void {
    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->getJson('/api/v1/operations/jobs?per_page=5000')
        ->assertStatus(422);

    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->getJson('/api/v1/operations/tasks/' . OperationTask::ACCESS_SYNC->value . '/runs?per_page=5000')
        ->assertStatus(422);
});

it('never mixes task runs into the queue listing', function (): void {
    // The two populations are separate by construction: `RunOperationTask` catches its own failure
    // and writes a FAILED run, so it never reaches `failed_jobs` or Horizon's failed list. This
    // pins that the jobs endpoint reports the queue rather than the ledger.
    $run = new TaskRun();
    $run->task_key = OperationTask::ACCESS_SYNC->value;
    $run->trigger = TaskRunTrigger::MANUAL;
    $run->status = TaskRunStatus::FAILED;
    $run->started_at = CarbonImmutable::now('UTC');
    $run->save();

    $response = $this->actingAs(operationsUser('SUPER_ADMIN'))->getJson('/api/v1/operations/jobs')->assertOk();

    /** @var list<array<string, mixed>> $jobs */
    $jobs = $response->json('jobs');

    expect(array_column($jobs, 'name'))->not->toContain(OperationTask::ACCESS_SYNC->value);
});

it('takes a law by its type, number and year, and refuses anything outside them', function (): void {
    Queue::fake();

    $actor = operationsUser('SUPER_ADMIN');
    $task = OperationTask::LEGISLATION_INGEST;
    $run = fn (array $arguments) => $this->actingAs($actor)->postJson("/api/v1/operations/tasks/{$task->value}/run", $arguments);

    // The type is a closed set, the number and year are bounded, and "from" is a date.
    $run(['act_type' => 'WHATEVER', 'act_number' => 227, 'act_year' => 2015])->assertStatus(422);
    $run(['act_type' => 'LEGE', 'act_number' => 0, 'act_year' => 2015])->assertStatus(422);
    $run(['act_type' => 'LEGE', 'act_number' => 227, 'act_year' => 1800])->assertStatus(422);
    $run(['act_type' => 'LEGE', 'act_number' => 227, 'act_year' => 2015, 'from' => 'yesterday'])->assertStatus(422);
    $run(['act_type' => 'LEGE', 'act_number' => 227, 'act_year' => 2015, 'portal_id' => '171280; rm -rf /'])->assertStatus(422);

    // There is no address to give: an old-style URL argument is simply not an argument.
    $run(['act_type' => 'LEGE', 'act_number' => 227, 'act_year' => 2015, 'url' => 'https://evil.example.com/'])
        ->assertStatus(202)
        ->assertJsonMissingPath('arguments.url');

    Queue::assertPushed(RunOperationTask::class);
});

it('leaves an empty optional date out of the command it runs', function (): void {
    Queue::fake();

    $task = OperationTask::LEGISLATION_INGEST;

    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", ['act_type' => 'LEGE', 'act_number' => 227, 'act_year' => 2015, 'from' => null])
        ->assertStatus(202)
        ->assertJsonPath('arguments', ['act_type' => 'LEGE', 'act_number' => 227, 'act_year' => 2015]);
});

it('runs a law found on the portal through the same task, by its portal id', function (): void {
    Queue::fake();

    $task = OperationTask::LEGISLATION_INGEST;

    $this->actingAs(operationsUser('SUPER_ADMIN'))
        ->postJson("/api/v1/operations/tasks/{$task->value}/run", ['act_type' => 'HOTARARE', 'act_number' => 4, 'act_year' => 2021, 'portal_id' => 236118])
        ->assertStatus(202)
        ->assertJsonPath('arguments.portal_id', 236118);
});
