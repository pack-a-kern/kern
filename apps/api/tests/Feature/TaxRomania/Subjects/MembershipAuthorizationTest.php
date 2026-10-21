<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\MembershipAuthorizationTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Security\SecurityEvent;
use Kern\Platform\Security\SecurityEventType;
use Kern\Platform\Tenancy\Contracts\TenantGate;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;
use Kern\Platform\Tenancy\Exceptions\UnauthorizedTenantAccessException;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('resolves the exact role a membership grants', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);

    expect($context->currentTenantId())->toBe($subject->id)
        // The platform holds the role as the application's own word for it, not as this enum:
        // a tenant context that knew `MembershipRole` would know what a taxpayer is.
        ->and($context->currentRole())->toBe(MembershipRole::OWNER->value)
        ->and($context->hasActiveTenant())->toBeTrue();
});

it('denies access and records a security event when no membership exists at all', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    $context = app(TenantContext::class);

    expect(fn () => $context->authorizeAndActivate($user->id, $subject->id))
        ->toThrow(UnauthorizedTenantAccessException::class);

    $event = SecurityEvent::query()->where('user_id', $user->id)->where('subject_id', $subject->id)->first();

    expect($event)->not->toBeNull()
        ->and($event?->event_type)->toBe(SecurityEventType::CROSS_TENANT_ACCESS_DENIED);
});

it('loses access immediately once a membership is revoked, exactly as if it never existed', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    $membership = SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    // Access works while active.
    (app(TenantContext::class))->authorizeAndActivate($user->id, $subject->id);

    $membership->revoked_at = now('UTC');
    $membership->save();

    expect(fn () => (app(TenantContext::class))->authorizeAndActivate($user->id, $subject->id))
        ->toThrow(UnauthorizedTenantAccessException::class);
});

it('throws instead of returning a stale or default subject when nothing has been activated', function (): void {
    $context = app(TenantContext::class);

    expect(fn () => $context->currentTenantId())->toThrow(NoTenantContextException::class)
        ->and(fn () => $context->currentRole())->toThrow(NoTenantContextException::class)
        ->and($context->hasActiveTenant())->toBeFalse();
});

/**
 * A membership in each role that has no rules yet, on a taxpayer someone else owns.
 *
 * @return array{User, TaxSubject}
 */
function memberWithoutRules(MembershipRole $role): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    foreach ([[$owner, MembershipRole::OWNER], [$member, $role]] as [$user, $held]) {
        SubjectMembership::create([
            'subject_id' => $subject->id,
            'user_id' => $user->id,
            'role' => $held,
            'permissions_json' => [],
            'valid_from' => now('UTC'),
        ]);
    }

    return [$member, $subject];
}

/*
 * The roles with a name and no rules. Until sharing defines what each may do, holding one opens
 * nothing: a VIEWER used to be able to change and delete everything an owner could, because nothing
 * read the role at all.
 */
dataset('roles without rules', [
    'ADMIN' => [MembershipRole::ADMIN],
    'ACCOUNTANT' => [MembershipRole::ACCOUNTANT],
    'VIEWER' => [MembershipRole::VIEWER],
]);

it('opens a taxpayer for its owner alone, and records each other role it refuses', function (MembershipRole $role): void {
    [$member, $subject] = memberWithoutRules($role);

    expect(fn () => app(TenantContext::class)->authorizeAndActivate($member->id, $subject->id))
        ->toThrow(UnauthorizedTenantAccessException::class);

    expect(SecurityEvent::query()->where('user_id', $member->id)->where('subject_id', $subject->id)->value('context_json'))
        ->toBe(['reason' => 'role_without_rules']);
})->with('roles without rules');

it('refuses a role without rules both reading and changing the taxpayer over HTTP', function (MembershipRole $role): void {
    [$member, $subject] = memberWithoutRules($role);

    $this->actingAs($member)->getJson("/api/v1/subjects/{$subject->id}/wallet/accounts")->assertForbidden();
    $this->actingAs($member)->postJson("/api/v1/subjects/{$subject->id}/wallet/accounts", [])->assertForbidden();
    $this->actingAs($member)->get("/api/v1/subjects/{$subject->id}/avatar")->assertForbidden();
})->with('roles without rules');

it('does not list a taxpayer a membership would not open', function (MembershipRole $role): void {
    [$member, $subject] = memberWithoutRules($role);

    expect($this->actingAs($member)->getJson('/api/v1/subjects')->assertOk()->json('data.*.id'))
        ->not->toContain($subject->id)
        ->and(app(TenantGate::class)->tenantsFor($member->id))->toBe([]);
})->with('roles without rules');
