<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\CrossTenantIsolationTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvCredentialPolicy;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Enums\SpvSyncRunStatus;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvMessage;
use Kern\TaxRomania\Spv\Models\SpvSession;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `docs/AGENTS.md` §21.4 / `BLUEPRINT.md` §26 M10 DoD ("one tax subject cannot access another
 * browser state") — every table this module owns uses `Kern\TaxRomania\Subjects\Tenancy\
 * ScopedToTenant`, the same test shape `tests/Feature/TaxRomania/Rental/CrossTenantIsolationTest.php`
 * establishes first.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

function spvOwnerAndSubject(): TaxSubject
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

    activateSpvSubject($user, $subject);

    return $subject;
}

function activateSpvSubject(User $user, TaxSubject $subject): void
{
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

it('never lets a subject query another subject\'s SpvConnection, SpvSession, SpvSyncRun, or SpvMessage rows', function (): void {
    $subjectA = spvOwnerAndSubject();

    $connectionA = SpvConnection::create([
        'tax_subject_id' => $subjectA->id,
        'auth_method' => SpvAuthMethod::USER_PASSWORD_OTP,
        'credential_policy' => SpvCredentialPolicy::EPHEMERAL_ONLY,
        'username_ciphertext' => 'owner-a@example.test',
        'status' => SpvConnectionStatus::PENDING,
    ]);

    $sessionA = SpvSession::create([
        'tax_subject_id' => $subjectA->id,
        'connection_id' => $connectionA->id,
        'browser_session_ref' => 'browser-session-a',
        'state' => SpvSessionState::CREATED,
        'created_at' => now('UTC'),
        'expires_at' => now('UTC')->addMinutes(15),
    ]);

    $syncRunA = SpvSyncRun::create([
        'tax_subject_id' => $subjectA->id,
        'connection_id' => $connectionA->id,
        'status' => SpvSyncRunStatus::RUNNING,
        'started_at' => now('UTC'),
    ]);

    $messageA = SpvMessage::create([
        'tax_subject_id' => $subjectA->id,
        'external_id_ciphertext' => 'anaf-message-1',
        'external_id_blind_index' => 'blind-a',
        'title_ciphertext' => 'Confidential title for subject A',
        'received_at' => now('UTC'),
    ]);

    // Switch tenant context to a second, unrelated subject.
    spvOwnerAndSubject();

    expect(SpvConnection::query()->find($connectionA->id))->toBeNull()
        ->and(SpvSession::query()->find($sessionA->id))->toBeNull()
        ->and(SpvSyncRun::query()->find($syncRunA->id))->toBeNull()
        ->and(SpvMessage::query()->find($messageA->id))->toBeNull();
});
