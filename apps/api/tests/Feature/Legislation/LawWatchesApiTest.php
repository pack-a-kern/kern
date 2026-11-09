<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\LawWatchesApiTest;

use Illuminate\Support\Facades\DB;
use Kern\Legislation\Models\LawWatch;

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

it('asks again for the password or code before a watch is kept, changed or removed', function (): void {
    $package = declared();
    withAuthenticator($this->operator);
    forgetVerification();

    $this->actingAs($this->operator)->postJson('/api/v1/legislation/watches', ['search_words' => 'exemplul', 'act_type' => 'ORDIN', 'first_year' => 2025, 'every_hours' => 168])->assertForbidden();
    $this->actingAs($this->operator)->patchJson("/api/v1/legislation/watches/{$package->id}", ['enabled' => false])->assertForbidden();
    $this->actingAs($this->operator)->deleteJson("/api/v1/legislation/watches/{$package->id}")->assertForbidden();
    $this->actingAs($this->operator)->getJson('/api/v1/legislation/watches')->assertOk();

    expect(LawWatch::query()->count())->toBe(1)->and($package->fresh()?->enabled)->toBeTruthy();
});
