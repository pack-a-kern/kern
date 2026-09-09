<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\DataTransferObjects\TotpEnrollment;
use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Exceptions\TotpAlreadyEnrolledException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserMfaMethod;
use App\Domain\Identity\Support\Totp\TotpVerifier;

/**
 * Starts (or restarts) TOTP enrollment: generates a fresh secret, stores it **unconfirmed**
 * (`confirmed_at IS NULL`), and returns the provisioning URI for the user's authenticator app.
 * `App\Domain\Identity\Actions\ConfirmTotpEnrollment` is the only thing that ever sets
 * `confirmed_at` — until then this method does not count toward `App\Domain\Identity\Support\
 * SpvMfaPolicy::hasStrongAuthentication()` or `App\Domain\Identity\Actions\
 * AttemptPasswordLogin::$mfaRequired`.
 *
 * Refuses to run if the account already has a *confirmed* TOTP method (`TotpAlreadyEnrolledException`
 * — see that class's docblock for why "disable then re-enroll" is a separate, unbuilt flow); an
 * abandoned unconfirmed attempt is silently replaced, since it never protected anything.
 */
final readonly class EnrollTotp
{
    public function __construct(
        private AuditChainService $audit,
        private TotpVerifier $totp,
    ) {
    }

    public function __invoke(User $user): TotpEnrollment
    {
        $existing = $user->mfaMethods()->where('type', MfaMethodType::TOTP)->first();

        if ($existing instanceof UserMfaMethod && $existing->isConfirmed()) {
            throw TotpAlreadyEnrolledException::create();
        }

        $secret = $this->totp->generateSecret();

        if ($existing instanceof UserMfaMethod) {
            $existing->secret_ciphertext = $secret;
            $existing->confirmed_at = null;
            $existing->save();
        } else {
            UserMfaMethod::create([
                'user_id' => $user->id,
                'type' => MfaMethodType::TOTP,
                'secret_ciphertext' => $secret,
            ]);
        }

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'MfaEnrollmentStarted',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['method' => MfaMethodType::TOTP->value]),
        ));

        return new TotpEnrollment($secret, $this->totp->provisioningUri($secret, $this->accountLabel($user)));
    }

    private function accountLabel(User $user): string
    {
        $email = $user->email_ciphertext;

        return is_string($email) && $email !== '' ? $email : $user->id;
    }
}
