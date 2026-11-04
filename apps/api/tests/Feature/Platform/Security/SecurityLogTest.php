<?php

declare(strict_types=1);

use Kern\Platform\Identity\Actions\CompleteMfaChallenge;
use Kern\Platform\Identity\Actions\CompletePasskeyLogin;
use Kern\Platform\Identity\Exceptions\InvalidMfaCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use Kern\Platform\Security\RecordSecurityEvent;
use Kern\Platform\Security\SecurityEvent;
use Kern\Platform\Security\SecurityEventType;

/*
 * The security log is written one way, and what a row may carry is checked there. The password
 * login's row is `AuditTrailTest`'s and the refused tenant's is `MembershipAuthorizationTest`'s;
 * these are the other two writers, and the check itself.
 */

it('records a refused second factor', function (): void {
    $user = User::factory()->create();

    expect(fn () => app(CompleteMfaChallenge::class)($user, '000000'))->toThrow(InvalidMfaCodeException::class);

    $event = SecurityEvent::query()->sole();

    expect($event->event_type)->toBe(SecurityEventType::MFA_CHALLENGE_FAILED)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->context_json)->toBe(['method' => 'mfa']);
});

it('records a refused passkey, with nothing about who tried it', function (): void {
    expect(fn () => app(CompletePasskeyLogin::class)('no-pending-ceremony', [], 'localhost'))->toThrow(PasskeyVerificationException::class);

    $event = SecurityEvent::query()->sole();

    expect($event->event_type)->toBe(SecurityEventType::LOGIN_FAILED)
        ->and($event->user_id)->toBeNull()
        ->and($event->context_json)->toBe(['method' => 'passkey']);
});

it('refuses a context that is not codes, and writes nothing', function (array $context): void {
    expect(fn () => app(RecordSecurityEvent::class)(SecurityEventType::LOGIN_FAILED, null, null, $context))
        ->toThrow(InvalidArgumentException::class);

    expect(SecurityEvent::query()->exists())->toBeFalse();
})->with([
    'an address' => [['email' => 'ion@example.test']],
    'a sentence' => [['reason' => 'Ion Popescu typed the wrong code']],
    'a nested value' => [['method' => ['password']]],
    'a key that is not a code' => [['E-mail' => 'password']],
]);

it('writes a context of codes as it was given', function (): void {
    app(RecordSecurityEvent::class)(SecurityEventType::LOGIN_FAILED, null, null, ['method' => 'password', 'reason' => 'no_active_membership']);

    expect(SecurityEvent::query()->sole()->context_json)->toBe(['method' => 'password', 'reason' => 'no_active_membership']);
});
