<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Exceptions\MfaNotEnrolledException;
use Kern\Platform\Identity\Models\User;

/**
 * `BLUEPRINT.md` §4.7: "MFA obligatoriu pentru conturi care conectează SPV."
 *
 * The reason the bar is higher here than for signing in at all: an account that connects SPV reaches
 * the state's own record of the taxpayer — every message ANAF has sent them — rather than this
 * installation's record of it. A password protects what is stored here; this protects a channel to
 * the tax authority.
 *
 * A confirmed TOTP method **or** at least one registered passkey both satisfy "strong
 * authentication" here, not TOTP alone: a WebAuthn passkey ceremony already combines possession
 * (the authenticator) with, in practice, a local biometric/PIN verification
 * (`AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED`,
 * `Kern\Platform\Identity\Support\Webauthn\PasskeyManager`) — it is not a weaker "single factor" than
 * password+TOTP, so requiring *both* a passkey and a separate TOTP enrollment on top of it would
 * add friction without adding security. An unconfirmed TOTP method (enrollment started but never
 * completed with a valid code) does not count — see `Kern\Platform\Identity\Models\
 * UserMfaMethod::isConfirmed()`.
 *
 * ## Where it is enforced
 *
 * `Kern\TaxRomania\Spv\Actions\RegisterSpvConnection` before an `SpvConnection` may exist, and
 * `Kern\TaxRomania\Spv\Actions\InitiateSpvAuthAttempt` again at each sign-in — the second because
 * MFA could have been un-enrolled since the connection was made, and a check that only ran once
 * would be a check on history.
 *
 * In the actions rather than the controllers, so a second call site cannot skip it. The thrown
 * `MfaNotEnrolledException` is rendered centrally as a 403 carrying `MFA_REQUIRED`, beside the
 * tenant refusal and for the same reason: every call site gets it without a controller importing
 * the exception to catch it.
 */
final class SpvMfaPolicy
{
    public function hasStrongAuthentication(User $user): bool
    {
        $hasConfirmedTotp = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();

        if ($hasConfirmedTotp) {
            return true;
        }

        return $user->passkeys()->exists();
    }

    public function ensureStrongAuthentication(User $user): void
    {
        if (! $this->hasStrongAuthentication($user)) {
            throw MfaNotEnrolledException::create();
        }
    }
}
