<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Exceptions\InvalidMfaCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Identity\Models\UserRecoveryCode;
use Kern\Platform\Identity\Support\Totp\TotpVerifier;
use Kern\Platform\Security\RecordSecurityEvent;
use Kern\Platform\Security\SecurityEventType;

/**
 * The second step of a password login that required MFA (`Kern\Platform\Identity\Actions\
 * AttemptPasswordLogin::$mfaRequired`). Tries the account's confirmed TOTP method first, then
 * falls back to matching `$code` against every one of the user's unused recovery codes
 * (`Kern\Platform\Identity\Models\UserRecoveryCode`) — there are only ever a handful
 * (`config('identity.recovery_codes.count')`), so a linear `Hash::check()` scan is not a
 * performance concern and needs no code-lookup index.
 *
 * ## Single-use enforcement
 *
 * A matched recovery code has `used_at` set in the same request, before returning — a replay of
 * the identical code string fails on the very next attempt because `UserRecoveryCode::query()`
 * here only ever considers `whereNull('used_at')` rows.
 *
 * An authenticator code is single-use too, by its time step. A code stays valid for its own period
 * and the skew either side, about 90 seconds, and nothing used to remember it had been used — so one
 * code seen over a shoulder, or relayed by a page that asked for it, signed in again behind its owner.
 * The step of every accepted code is kept, and a code from that step or an earlier one is refused as
 * a wrong code would be. Claimed with one conditional `UPDATE`, so two requests carrying the same code
 * at the same moment cannot both pass.
 */
final readonly class CompleteMfaChallenge
{
    public function __construct(
        private AuditChainService $audit,
        private TotpVerifier $totp,
        private RecordSecurityEvent $securityLog,
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

        $step = $this->totp->matchingStep($method->secret_ciphertext, $code);

        if ($step === null) {
            return false;
        }

        $claimed = UserMfaMethod::query()
            ->whereKey($method->id)
            ->where(
                /** @param Builder<UserMfaMethod> $query */
                static fn (Builder $query): Builder => $query->whereNull('last_used_step')->orWhere('last_used_step', '<', $step),
            )
            ->update(['last_used_step' => $step, 'last_used_at' => now('UTC')]);

        return $claimed === 1;
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

        ($this->securityLog)(SecurityEventType::MFA_CHALLENGE_FAILED, $user->id, null, ['method' => 'mfa']);
    }
}
