<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Exceptions\MfaNotEnrolledException;
use Kern\Platform\Identity\Models\User;

/**
 * `BLUEPRINT.md` §4.7: "MFA obligatoriu pentru conturi care conectează SPV." SPV automation itself is
 * Milestone 10 (`docs/STATUS.md` — not started), so there is nothing yet that calls this class in
 * production — but the *policy* is a Milestone 1 deliverable per this task's own brief ("express
 * this as an enforceable policy/capability check that Milestone 10 can require — not as dead code
 * for a feature that doesn't exist"). This class is that enforceable check: real logic, real
 * tests, zero speculative SPV plumbing.
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
 * ## How Milestone 10 uses this
 *
 * Before creating an `SpvConnection` for a `User` (`BLUEPRINT.md` §6.11), that module's registration
 * action calls `app(SpvMfaPolicy::class)->ensureStrongAuthentication($user)` — a thrown
 * `MfaNotEnrolledException` is caught by that module's controller and surfaced as "enroll MFA
 * first," exactly the same shape `Kern\Platform\Tenancy\TenantContext::
 * authorizeAndActivate()` already uses for its own "throw, let the HTTP layer translate" contract.
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
