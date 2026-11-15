<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\LawWatchesApiTest;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kern\Legislation\Events\LawWatchRan;
use Kern\Legislation\Jobs\RunLawWatchNow;
use Kern\Legislation\Models\IgnoredAct;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Watches\LawWatchRunner;

/** A search kept by a person, by the API, and its id. Made up. */
function keep(\Tests\TestCase $case, \Kern\Platform\Identity\Models\User $user): string
{
    $id = $case->actingAs($user)->postJson('/api/v1/legislation/watches', ['search_words' => 'exemplul', 'act_type' => 'ORDIN', 'first_year' => 2025, 'every_hours' => 168])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

/** A package's watch, as the hourly run mirrors one. Made up. */
function declared(): LawWatch
{
    return LawWatch::query()->create([
        'key' => 'example.orders', 'declared_by' => 'kern/example', 'search_words' => 'exemplul de ordin', 'act_type' => 'ORDIN',
        'title_pattern' => '/exemplul/u', 'first_year' => 2024, 'every_hours' => 168, 'enabled' => true, 'next_run_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->operator = settingsAdminUser();
    verifySession();
});

it('is for those who may start a reading, and no one else', function (): void {
    $this->getJson('/api/v1/legislation/watches')->assertUnauthorized();
    $this->actingAs(ordinaryUser())->getJson('/api/v1/legislation/watches')->assertForbidden();
    $this->actingAs(ordinaryUser())->postJson('/api/v1/legislation/watches', [])->assertForbidden();
});

it('keeps a search a person asks to keep, runs it at the next hourly run, and records it', function (): void {
    $kept = $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches', [
        'search_words' => '  exemplul de hotărâre  ', 'act_type' => 'HOTARARE', 'first_year' => 2025, 'every_hours' => 24,
    ])->assertCreated();

    $watch = LawWatch::query()->whereKey($kept->json('id'))->sole();

    expect($kept->json('mine'))->toBeTrue()
        ->and($kept->json('declaredBy'))->toBeNull()
        ->and($watch->search_words)->toBe('exemplul de hotărâre')
        ->and($watch->title_pattern)->toBeNull()
        ->and($watch->next_run_at->lte(now()))->toBeTrue()
        ->and(DB::table('audit_events')->where('event_type', 'LawWatchCreated')->count())->toBe(1);
});

it('refuses a search it cannot keep', function (array $asked): void {
    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches', [
        'search_words' => 'exemplul', 'act_type' => 'ORDIN', 'first_year' => 2025, 'every_hours' => 24, ...$asked,
    ])->assertUnprocessable();
})->with([
    'an interval not offered' => [['every_hours' => 1]],
    'a type that is not one' => [['act_type' => 'ordin; drop']],
    'a year before the library' => [['first_year' => 1990]],
    'a year to come' => [['first_year' => 2999]],
    'words too short' => [['search_words' => 'ab']],
]);

it('lists the packages’ watches first, with the intervals a person may choose', function (): void {
    declared();
    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches', ['search_words' => 'exemplul', 'act_type' => 'ORDIN', 'first_year' => 2025, 'every_hours' => 168])->assertCreated();

    $listed = $this->actingAs($this->operator)->getJson('/api/v1/legislation/watches')->assertOk();

    $watches = $listed->json('watches');

    expect(array_column(is_array($watches) ? $watches : [], 'declaredBy'))->toBe(['kern/example', null])
        ->and($listed->json('intervals'))->toBe([24, 168, 720]);
});

it('pauses a package’s watch but keeps its interval, and lets a person’s run more or less often', function (): void {
    $package = declared();
    $mine = keep($this, $this->operator);

    $this->actingAs($this->operator)->patchJson("/api/v1/legislation/watches/{$package->id}", ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
    $this->actingAs($this->operator)->patchJson("/api/v1/legislation/watches/{$package->id}", ['every_hours' => 24])->assertUnprocessable();
    $this->actingAs($this->operator)->patchJson("/api/v1/legislation/watches/{$mine}", ['every_hours' => 720])->assertOk()->assertJsonPath('everyHours', 720);
});

it('deletes a person’s watch, recorded, and refuses a package’s', function (): void {
    $package = declared();
    $mine = keep($this, $this->operator);

    $this->actingAs($this->operator)->deleteJson("/api/v1/legislation/watches/{$package->id}")->assertStatus(409)->assertJsonPath('reason', 'WATCH_IS_DECLARED');
    $this->actingAs($this->operator)->deleteJson("/api/v1/legislation/watches/{$mine}")->assertNoContent();

    expect(LawWatch::query()->pluck('id')->all())->toBe([$package->id])
        ->and(DB::table('audit_events')->where('event_type', 'LawWatchDeleted')->count())->toBe(1);
});

it('leaves a person’s watch to that person, and records every pause or change', function (): void {
    $package = declared();
    $theirs = keep($this, $this->operator);
    $colleague = settingsAdminUser();

    $this->actingAs($colleague)->patchJson("/api/v1/legislation/watches/{$theirs}", ['enabled' => false])->assertForbidden();
    $this->actingAs($colleague)->deleteJson("/api/v1/legislation/watches/{$theirs}")->assertForbidden();
    $this->actingAs($colleague)->patchJson("/api/v1/legislation/watches/{$package->id}", ['enabled' => false])->assertOk();

    expect(LawWatch::query()->whereKey($theirs)->value('enabled'))->toBeTruthy()
        ->and(DB::table('audit_events')->where('event_type', 'LawWatchChanged')->where('resource_id', $package->id)->count())->toBe(1);
});

it('keeps no more watches for a person than allowed', function (): void {
    config()->set('legislation.watches.max_per_person', '1');
    keep($this, $this->operator);

    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches', ['search_words' => 'altul', 'act_type' => 'ORDIN', 'first_year' => 2025, 'every_hours' => 168])
        ->assertUnprocessable()->assertJsonPath('reason', 'TOO_MANY_WATCHES');
});

it('asks again for the password or code before a watch is kept, changed or removed, or an act allowed again', function (): void {
    $package = declared();
    $ignored = IgnoredAct::query()->create(['portal_document_id' => '900001', 'title' => 'ORDIN nr. 77 privind exemplul']);
    withAuthenticator($this->operator);
    forgetVerification();

    $this->actingAs($this->operator)->deleteJson("/api/v1/legislation/watches/ignored/{$ignored->id}")->assertForbidden();
    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches', ['search_words' => 'exemplul', 'act_type' => 'ORDIN', 'first_year' => 2025, 'every_hours' => 168])->assertForbidden();
    $this->actingAs($this->operator)->patchJson("/api/v1/legislation/watches/{$package->id}", ['enabled' => false])->assertForbidden();
    $this->actingAs($this->operator)->deleteJson("/api/v1/legislation/watches/{$package->id}")->assertForbidden();
    $this->actingAs($this->operator)->getJson('/api/v1/legislation/watches')->assertOk();

    expect(LawWatch::query()->count())->toBe(1)->and($package->fresh()?->enabled)->toBeTruthy()->and(IgnoredAct::query()->count())->toBe(1);
});

it('runs a watch now on the queue, once however often asked, and not one that is paused', function (): void {
    Queue::fake();
    $watch = declared();

    $this->actingAs($this->operator)->postJson("/api/v1/legislation/watches/{$watch->id}/run")
        ->assertStatus(202)
        ->assertJsonPath('running', true);
    $this->actingAs($this->operator)->postJson("/api/v1/legislation/watches/{$watch->id}/run")->assertStatus(202);

    Queue::assertPushed(RunLawWatchNow::class, 1);

    $watch->update(['enabled' => false, 'run_requested_at' => null]);
    $this->actingAs($this->operator)->postJson("/api/v1/legislation/watches/{$watch->id}/run")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'WATCH_PAUSED');
});

/** One package-declared watch, in configuration, as the setup screen reaches it by key (ADR 0027). */
function declareInConfig(): void
{
    config(['legislation.watches.declared' => [
        'example.orders' => [
            'declared_by' => 'kern/example',
            'search' => 'exemplul de ordin',
            'act_type' => 'ORDIN',
            'title_pattern' => '/exemplul/u',
            'first_year' => 2024,
            'every_hours' => 168,
        ],
    ]]);
}

it('runs a package-declared watch by key, mirroring its row first on a fresh install', function (): void {
    Queue::fake();
    declareInConfig();

    // The fresh-install case: nothing has mirrored the declarations yet.
    expect(LawWatch::query()->where('key', 'example.orders')->exists())->toBeFalse();

    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches/declared/example.orders/run')
        ->assertStatus(202)
        ->assertJsonPath('running', true);

    $watch = LawWatch::query()->where('key', 'example.orders')->sole();
    expect($watch->search_words)->toBe('exemplul de ordin')
        ->and($watch->declared_by)->toBe('kern/example');
    Queue::assertPushed(RunLawWatchNow::class, 1);

    // Asked again before the first run ends, it is still one run.
    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches/declared/example.orders/run')->assertStatus(202);
    Queue::assertPushed(RunLawWatchNow::class, 1);
});

it('404s a declared-watch key that no package declares', function (): void {
    config(['legislation.watches.declared' => []]);

    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches/declared/example.orders/run')
        ->assertNotFound();
});

it('does not resurrect a declared watch a person has paused', function (): void {
    Queue::fake();
    declareInConfig();
    LawWatch::query()->create([
        'key' => 'example.orders', 'declared_by' => 'kern/example', 'search_words' => 'exemplul de ordin',
        'act_type' => 'ORDIN', 'first_year' => 2024, 'every_hours' => 168, 'enabled' => false, 'next_run_at' => now(),
    ]);

    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches/declared/example.orders/run')
        ->assertStatus(409)
        ->assertJsonPath('reason', 'WATCH_PAUSED');
    Queue::assertNothingPushed();
});

it('runs a declared watch only for those who may start a reading', function (): void {
    $this->postJson('/api/v1/legislation/watches/declared/example.orders/run')->assertUnauthorized();
    $this->actingAs(ordinaryUser())->postJson('/api/v1/legislation/watches/declared/example.orders/run')->assertForbidden();
});

it('runs a watch on a queue of its own, not the one laws are read on, with a worker that outlasts it', function (): void {
    $job = new RunLawWatchNow('00000000-0000-0000-0000-000000000000', $this->operator->id);
    $supervisor = 'horizon.defaults.supervisor-legislation-watches';

    expect($job->queue)->toBe(config('legislation.watches.queue'))
        ->and($job->queue)->not->toBe(config('legislation.queue'))
        ->and(config()->array($supervisor . '.queue'))->toBe([$job->queue])
        ->and(config()->integer($supervisor . '.timeout'))->toBeGreaterThanOrEqual($job->timeout)
        ->and(config()->integer('queue.connections.' . config()->string($supervisor . '.connection') . '.retry_after'))->toBeGreaterThan(config()->integer($supervisor . '.timeout'));
});

it('says on the asker\'s own channel when a watch run now is done, and lets the request go', function (): void {
    Event::fake([LawWatchRan::class]);
    $watch = declared();
    $watch->update(['enabled' => false, 'run_requested_at' => now()]);

    (new RunLawWatchNow($watch->id, $this->operator->id))->handle(app(LawWatchRunner::class));

    expect($watch->refresh()->run_requested_at)->toBeNull();
    Event::assertDispatched(LawWatchRan::class, fn (LawWatchRan $ran): bool => $ran->watchId === $watch->id
        && array_map(static fn (PrivateChannel $channel): string => $channel->name, $ran->broadcastOn()) === ["private-users.{$this->operator->id}"]
        && $ran->broadcastWith() === ['id' => $watch->id]);
});

it('lists the acts the watches ignore, and allows one again, recorded', function (): void {
    $ignored = IgnoredAct::query()->create(['portal_document_id' => '900001', 'title' => 'ORDIN nr. 77 privind exemplul']);

    $this->actingAs($this->operator)->getJson('/api/v1/legislation/watches')
        ->assertJsonPath('ignored.0.id', $ignored->id)
        ->assertJsonPath('ignored.0.portalId', '900001');

    $this->actingAs($this->operator)->deleteJson("/api/v1/legislation/watches/ignored/{$ignored->id}")->assertNoContent();

    expect(IgnoredAct::query()->count())->toBe(0)
        ->and(DB::table('audit_events')->where('event_type', 'IgnoredActAllowed')->count())->toBe(1);
});
