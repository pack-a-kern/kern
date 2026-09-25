<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\CrossTenantIsolationTest;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Feature\TaxRomania\Subjects\Fixtures\ScopedFixtureRecord;

/**
 * The mandatory cross-tenant isolation test (`docs/AGENTS.md` §21.4, `BLUEPRINT.md` §5.3's
 * "Test automat obligatoriu pentru cross-tenant isolation"): a user authorized for one
 * `TaxSubject` must not be able to read, list, update, or delete any row belonging to a
 * different `TaxSubject`, through the one mechanism every future business module is meant to
 * use (`Kern\Platform\Tenancy\ScopedToTenant` + `TenantContext`) — see
 * `Fixtures\ScopedFixtureRecord`.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $blindIndexProvider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($blindIndexProvider));

    Schema::dropIfExists('scoped_fixture_records');
    Schema::create('scoped_fixture_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tax_subject_id')->index();
        $table->string('label');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('scoped_fixture_records');
});

/**
 * @return array{0: User, 1: TaxSubject}
 */
function ownerAndSubject(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject];
}

function activateAs(User $user, TaxSubject $subject): TenantContext
{
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    return $context;
}

it('never returns another subject\'s row from a list query', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    ScopedFixtureRecord::create(['label' => 'subject A secret record']);

    activateAs($userB, $subjectB);
    ScopedFixtureRecord::create(['label' => 'subject B record']);

    $visible = ScopedFixtureRecord::query()->pluck('label')->all();

    expect($visible)->toBe(['subject B record'])
        ->and($visible)->not->toContain('subject A secret record');
});

it('never returns another subject\'s row from a single-record lookup', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    $recordA = ScopedFixtureRecord::create(['label' => 'subject A record']);

    activateAs($userB, $subjectB);

    expect(ScopedFixtureRecord::find($recordA->id))->toBeNull();
});

it('cannot update another subject\'s row', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    $recordA = ScopedFixtureRecord::create(['label' => 'original']);

    activateAs($userB, $subjectB);
    $affected = ScopedFixtureRecord::query()->where('id', $recordA->id)->update(['label' => 'tampered']);

    expect($affected)->toBe(0);

    activateAs($userA, $subjectA);
    expect(ScopedFixtureRecord::query()->findOrFail($recordA->id)->label)->toBe('original');
});

it('cannot delete another subject\'s row', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    $recordA = ScopedFixtureRecord::create(['label' => 'still here']);

    activateAs($userB, $subjectB);
    $deleted = ScopedFixtureRecord::query()->where('id', $recordA->id)->delete();

    expect($deleted)->toBe(0);

    activateAs($userA, $subjectA);
    expect(ScopedFixtureRecord::query()->find($recordA->id))->not->toBeNull();
});

it('auto-tags a newly created row with the active subject, ignoring nothing the caller forgot', function (): void {
    [$user, $subject] = ownerAndSubject();

    activateAs($user, $subject);
    $record = ScopedFixtureRecord::create(['label' => 'auto tagged']);

    expect($record->tax_subject_id)->toBe($subject->id);
});

it('refuses to run any scoped query at all when no tenant context is bound', function (): void {
    app()->instance(TenantContext::class, app(TenantContext::class));

    expect(fn () => ScopedFixtureRecord::query()->get())->toThrow(NoTenantContextException::class);
});

it('allows an explicit, named escape hatch for genuinely cross-tenant system queries', function (): void {
    [$userA, $subjectA] = ownerAndSubject();
    [$userB, $subjectB] = ownerAndSubject();

    activateAs($userA, $subjectA);
    ScopedFixtureRecord::create(['label' => 'subject A']);

    activateAs($userB, $subjectB);
    ScopedFixtureRecord::create(['label' => 'subject B']);

    $everything = ScopedFixtureRecord::withoutGlobalScope(TenantScope::class)
        ->pluck('label')
        ->all();

    expect($everything)->toHaveCount(2)
        ->and($everything)->toContain('subject A', 'subject B');
});
