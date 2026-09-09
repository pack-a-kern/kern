<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Exceptions\InvalidMfaCodeException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserMfaMethod;
use App\Domain\Identity\Models\UserRecoveryCode;
use App\Domain\Identity\Support\Totp\TotpVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The second step of a password login that required MFA (`App\Domain\Identity\Actions\
 * AttemptPasswordLogin::$mfaRequired`). Tries the account's confirmed TOTP method first, then
 * falls back to matching `$code` against every one of the user's unused recovery codes
 * (`App\Domain\Identity\Models\UserRecoveryCode`) — there are only ever a handful
 * (`config('identity.recovery_codes.count')`), so a linear `Hash::check()` scan is not a
 * performance concern and needs no code-lookup index.
 *
 * ## Single-use enforcement
 *
 * A matched recovery code has `used_at` set in the same request, before returning — a replay of
 * the identical code string fails on the very next attempt because `UserRecoveryCode::query()`
 * here only ever considers `whereNull('used_at')` rows.
 */
final readonly class CompleteMfaChallenge
{
    public function __construct(
        private AuditChainService $audit,
        private TotpVerifier $totp,
    ) {
    }

    public function __invoke(User $user, string $code): void
    {
        if ($this->verifyTotp($user, $code)) {
            $this->recordSuccess($user, 'totp');

            return;
        }

        if ($this->verifyRecoveryCode($user, $code)) {
            $this->recordSuccess($user, 'recovery_code');

            return;
        }

        $this->recordFailure($user);

        throw InvalidMfaCodeException::create();
    }

    private function verifyTotp(User $user, string $code): bool
    {
        /** @var UserMfaMethod|null $method */
        $method = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->first();

        if ($method === null) {
            return false;
        }

        if (! $this->totp->verify($method->secret_ciphertext, $code)) {
            return false;
        }

        $method->last_used_at = now('UTC');
        $method->save();

        return true;
    }

    private function verifyRecoveryCode(User $user, string $code): bool
    {
        $candidates = UserRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($code, $candidate->code_hash)) {
                $candidate->used_at = now('UTC');
                $candidate->save();

                $this->audit->record(AuditEventInput::occurringNow(
                    subjectId: null,
                    actorId: $user->id,
                    eventType: 'AuthRecoveryCodeUsed',
                    resourceType: 'UserRecoveryCode',
                    resourceId: $candidate->id,
                    payloadHash: $this->audit->hashPayload(['remaining' => $candidates->count() - 1]),
                ));

                return true;
            }
        }

        return false;
    }

    private function recordSuccess(User $user, string $method): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'AuthLoginSucceeded',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['method' => $method]),
        ));
    }

    private function recordFailure(User $user): void
    {
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'AuthMfaChallengeFailed',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['method' => 'mfa']),
        ));

        DB::table('security_events')->insert([
            'id' => (string) Str::ulid(),
            'event_type' => 'MFA_CHALLENGE_FAILED',
            'user_id' => $user->id,
            'subject_id' => null,
            'context_json' => json_encode(['method' => 'mfa'], JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);
    }
}
