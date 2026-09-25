<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Spv\RevokeSpvConnectionTest;

use App\Domain\Spv\Actions\RevokeSpvConnection;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvCredentialPolicy;
use App\Domain\Spv\Models\SpvConnection;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Support\Facades\Artisan;
use Kern\Audit\Models\AuditEvent;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;

/** The natural counterpart to connecting SPV — a user must be able to disconnect it. */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('revokes a connection and records an audit event', function (): void {
    $actor = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $actor->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($actor->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    $connection = SpvConnection::create([
        'tax_subject_id' => $subject->id,
        'auth_method' => SpvAuthMethod::CERTIFICATE,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'status' => SpvConnectionStatus::ACTIVE,
    ]);

    $revoked = app(RevokeSpvConnection::class)($connection->id, $actor->id);

    expect($revoked->status)->toBe(SpvConnectionStatus::REVOKED);

    expect(AuditEvent::query()
        ->where('resource_id', $connection->id)
        ->where('event_type', 'SpvConnectionRevoked')
        ->where('actor_id', $actor->id)
        ->exists())->toBeTrue();
});
