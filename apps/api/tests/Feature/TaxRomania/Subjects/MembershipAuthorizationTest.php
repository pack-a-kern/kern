<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Subjects\MembershipAuthorizationTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Tenancy\Exceptions\NoTenantContextException;
use Kern\Platform\Tenancy\Exceptions\UnauthorizedTenantAccessException;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SecurityEvent;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Tests\Fakes\InMemoryKeyManagementService;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $blindIndexProvider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($blindIndexProvider));
});

it('resolves the exact role a membership grants', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::ACCOUNTANT,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);

    expect($context->currentTenantId())->toBe($subject->id)
        // The platform holds the role as the application's own word for it, not as this enum:
        // a tenant context that knew `MembershipRole` would know what a taxpayer is.
        ->and($context->currentRole())->toBe(MembershipRole::ACCOUNTANT->value)
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
        ->and($event?->event_type)->toBe('CROSS_TENANT_ACCESS_DENIED');
});

it('loses access immediately once a membership is revoked, exactly as if it never existed', function (): void {
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    $membership = SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::VIEWER,
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
