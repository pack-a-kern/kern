<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\DataTransferObjects\PasswordLoginResult;
use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Verifies an email + password pair and reports whether MFA must follow before a session can be
 * established (`PasswordLoginResult::$mfaRequired`) — this action never calls `Auth::login()` or
 * touches the session itself (`docs/handoff/017-authentication.md` "Key decisions": session
 * lifecycle is an HTTP-layer concern, `App\Http\Controllers\Api\V1\Auth\LoginController` owns it).
 *
 * ## User-enumeration resistance (`BLUEPRINT.md` §4.1, `docs/AGENTS.md` §8)
 *
 * An unknown email and a known email with the wrong password must be indistinguishable by
 * response content *and* by timing. Both cases reach exactly one `Hash::check()` call before this
 * action decides anything: if the email is unknown (or belongs to a passkey-only account with no
 * `password_hash`), `Hash::check()` runs against a stable dummy Argon2id hash
 * (`dummyHash()`, cached forever — computed once, not per request, so its cost is representative
 * without paying it twice) instead of skipping the call. `Hash::check()`'s own cost is dominated by
 * recomputing Argon2id over the *stored* hash's parameters, not by whether the comparison
 * ultimately succeeds — so both branches do the same expensive work before either can return.
 */
final readonly class AttemptPasswordLogin
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(string $email, string $password): PasswordLoginResult
    {
        $user = User::query()->whereEmail($email)->first();
        $hashToCheck = $user !== null ? ($user->password_hash ?? $this->dummyHash()) : $this->dummyHash();

        $passwordMatches = Hash::check($password, $hashToCheck);

        if ($user === null || ! $passwordMatches) {
            $this->recordFailure($user?->id);

            throw InvalidCredentialsException::create();
        }

        $mfaRequired = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: $mfaRequired ? 'AuthPasswordVerifiedPendingMfa' : 'AuthLoginSucceeded',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['method' => 'password']),
        ));

        return new PasswordLoginResult($user, $mfaRequired);
    }

    private function recordFailure(?string $userId): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $userId,
            eventType: 'AuthLoginFailed',
            resourceType: 'User',
            resourceId: $userId,
            payloadHash: $this->audit->hashPayload(['method' => 'password']),
        ));

        DB::table('security_events')->insert([
            'id' => (string) Str::ulid(),
            'event_type' => 'LOGIN_FAILED',
            'user_id' => $userId,
            'subject_id' => null,
            'context_json' => json_encode(['method' => 'password'], JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);
    }

    private function dummyHash(): string
    {
        /** @var string $hash */
        $hash = Cache::rememberForever(
            'identity:dummy-password-hash',
            static fn (): string => Hash::make(Str::random(32)),
        );

        return $hash;
    }
}
