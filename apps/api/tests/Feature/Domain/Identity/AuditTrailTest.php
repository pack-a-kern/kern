<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Identity\AuditTrailTest;

use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Audit\Services\ChainVerifier;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

/**
 * `BLUEPRINT.md` §4.8 (audit tamper evidence) — every authentication event this module records lands
 * on the hash-chained, verifiable audit trail (`App\Domain\Audit`), on the "system" chain
 * (`AuditChainService::SYSTEM_CHAIN_KEY`) since a login is not scoped to any one `TaxSubject`.
 * `security_events` (a separate, non-chained operational log, `docs/handoff/011-*`'s "Key
 * decisions" #9) is checked alongside it for the failure-reason cases it's meant for.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * @return list<string>
 */
function auditEventTypes(): array
{
    /** @var list<string> $types */
    $types = DB::table('audit_events')
        ->where('chain_key', AuditChainService::SYSTEM_CHAIN_KEY)
        ->orderBy('sequence')
        ->pluck('event_type')
        ->all();

    return $types;
}

it('records a successful login and a failed login attempt, and the chain verifies', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk();

    expect(auditEventTypes())->toBe(['AuthLoginFailed', 'AuthLoginSucceeded']);

    $result = app(ChainVerifier::class)->verify(AuditChainService::SYSTEM_CHAIN_KEY);
    expect($result->ok)->toBeTrue();
});

it('records MFA enrollment, a pending-MFA login, and the MFA challenge success', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    $this->postJson('/api/v1/auth/login', ['email' => 'ion@example.com', 'password' => 'correct-horse-battery-staple'])->assertOk();

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll'), 'secret');
    $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()])->assertOk();
    $this->postJson('/api/v1/auth/logout')->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'ion@example.com', 'password' => 'correct-horse-battery-staple'])
        ->assertJson(['mfa_required' => true]);

    $this->postJson('/api/v1/auth/mfa/challenge', ['code' => TOTP::createFromSecret($secret)->now()])->assertOk();

    expect(auditEventTypes())->toBe([
        'AuthLoginSucceeded', // first, MFA-less login
        'MfaEnrollmentStarted',
        'MfaEnrolled',
        'AuthSessionRevoked', // logout
        'AuthPasswordVerifiedPendingMfa',
        'AuthLoginSucceeded', // via the MFA challenge
    ]);

    $result = app(ChainVerifier::class)->verify(AuditChainService::SYSTEM_CHAIN_KEY);
    expect($result->ok)->toBeTrue();
});

it('writes a security_events row on a failed login, with no PII in the context', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ion@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422);

    $event = DB::table('security_events')->where('event_type', 'LOGIN_FAILED')->sole();

    expect($event->user_id)->toBe($user->id);
    $context = json_decode((string) $event->context_json, true, flags: JSON_THROW_ON_ERROR);
    expect($context)->toBe(['method' => 'password']);
    expect(json_encode($context))->not->toContain('wrong-password');
});

it('never records a TOTP secret, password, or recovery code in the audit payload hash inputs', function (): void {
    $user = User::factory()->withEmail('ion@example.com')->create();
    $user->password_hash = Hash::make('correct-horse-battery-staple');
    $user->save();
    $this->postJson('/api/v1/auth/login', ['email' => 'ion@example.com', 'password' => 'correct-horse-battery-staple'])->assertOk();

    $secret = jsonString($this->postJson('/api/v1/auth/mfa/totp/enroll'), 'secret');
    $codes = jsonStringList(
        $this->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => TOTP::createFromSecret($secret)->now()]),
        'recovery_codes',
    );

    // payload_hash is a SHA-256 digest, never the plaintext — but assert the raw secret/codes
    // never appear anywhere in the stored rows at all, belt and suspenders.
    $rows = DB::table('audit_events')->get();
    foreach ($rows as $row) {
        $serialized = json_encode($row);
        expect($serialized)->not->toContain($secret);
        foreach ($codes as $code) {
            expect($serialized)->not->toContain($code);
        }
        expect($serialized)->not->toContain('correct-horse-battery-staple');
    }
});
