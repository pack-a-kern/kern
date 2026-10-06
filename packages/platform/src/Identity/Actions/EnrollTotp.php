<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\DataTransferObjects\TotpEnrollment;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Exceptions\TotpAlreadyEnrolledException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserMfaMethod;
use Kern\Platform\Identity\Support\Totp\TotpVerifier;

/**
 * Starts (or restarts) TOTP enrollment: generates a fresh secret, stores it **unconfirmed**
 * (`confirmed_at IS NULL`), and returns the provisioning URI for the user's authenticator app.
 * `Kern\Platform\Identity\Actions\ConfirmTotpEnrollment` is the only thing that ever sets
 * `confirmed_at` — until then this method does not count toward `Kern\Platform\Identity\Support\
 * StepUpPolicy::canVerify()` or `Kern\Platform\Identity\Actions\
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
