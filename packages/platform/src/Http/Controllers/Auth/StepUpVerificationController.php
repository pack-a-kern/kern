<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Actions\CompleteMfaChallenge;
use Kern\Platform\Identity\Enums\StepUpFactor;
use Kern\Platform\Identity\Exceptions\InvalidMfaCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\StepUpPolicy;

/**
 * `POST /api/v1/auth/step-up` — proves, inside an existing session, that the person at the keyboard
 * is still the account's owner, so that
 * `Kern\Platform\Identity\Http\Middleware\RequiresRecentVerification` will let a sensitive act
 * through for the length of the window.
 *
 * ## The account is asked for one factor, and the server decides which
 *
 * `challengeFor()` picks it, the refusal that opened the dialog already named it, and this endpoint
 * accepts that one only. A submitted password is not accepted from an account holding an
 * authenticator app, however the request is shaped: a gate that takes either is worth its weaker
 * half, and anybody who can reach this endpoint can choose what to put in the body.
 *
 * ## Why the code path reuses the login challenge wholesale
 *
 * `CompleteMfaChallenge` already verifies a TOTP code against the enrolled secret, falls back to the
 * account's unused recovery codes, marks a matched recovery code used in the same request so a replay
 * fails, and records the outcome. Every one of those properties is wanted here unchanged. A second
 * implementation of "is this code right" is the kind of duplication that drifts apart until one of
 * the two has a weaker answer.
 *
 * The difference between the two endpoints is not the verification — it is what a success grants. There
 * it establishes a session; here it stamps one that already exists.
 *
 * ## Why the password path has a budget of its own
 *
 * A code expires every thirty seconds and has a million values; the route's shared budget is sized for
 * guessing at one. A password does not rotate, so the same budget spent against it is a slow but real
 * oracle — on a session that is, by assumption here, possibly not the owner's. It gets a tighter one,
 * counted separately so that fumbling a password cannot spend the allowance for a code or the reverse.
 *
 * ## Failures are a validation error, not a 403
 *
 * A wrong answer is something the person can fix by typing again, and the dialog shows it against the
 * field. A 403 would be read by the client's own interceptor as "you must verify", which is what it
 * was already doing — so answering a bad code that way would loop.
 */
final class StepUpVerificationController
{
    public function __invoke(
        Request $request,
        CompleteMfaChallenge $complete,
        StepUpPolicy $policy,
        AuditChainService $audit,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $factor = $policy->challengeFor($user);

        // The middleware says this before the dialog ever opens, but the endpoint is reachable on its
        // own and must not pretend to have verified an account that holds nothing to verify.
        if ($factor === null) {
            throw ValidationException::withMessages([
                // Not on `code` or `password`: the dialog translates an error on the field it asked
                // about into "that was not right", and this is a different thing entirely.
                'factor' => [__('platform::auth.nothing_to_confirm_with')],
            ]);
        }

        match ($factor) {
            StepUpFactor::TOTP => $this->confirmCode($request, $user, $complete),
            StepUpFactor::PASSWORD => $this->confirmPassword($request, $user, $audit),
        };

        $policy->markVerified($request->session());

        return response()->json(['verified_for_seconds' => $policy->windowSeconds()]);
    }

    private function confirmCode(Request $request, User $user, CompleteMfaChallenge $complete): void
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);

        try {
            $complete($user, $data['code']);
        } catch (InvalidMfaCodeException $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }
    }

    /**
     * The password the session was opened with, typed again.
     *
     * The hash is known to be present — `challengeFor()` returned `PASSWORD` because it is — so there
     * is no absent-hash branch here and no dummy comparison to keep the timing even. Who holds a
     * password is not a secret from the account that holds it.
     */
    private function confirmPassword(Request $request, User $user, AuditChainService $audit): void
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:1024']]);

        $budget = $this->passwordBudget($request, $user);

        if (RateLimiter::tooManyAttempts($budget['key'], $budget['attempts'])) {
            // 429, as the route's own throttle answers and as `lib/apiErrors.ts` already translates.
            // A 422 here would have been shown as "that password is wrong", which it is not.
            throw new ThrottleRequestsException(__('platform::auth.too_many_attempts'));
        }

        if (! Hash::check($data['password'], (string) $user->password_hash)) {
            RateLimiter::hit($budget['key'], $budget['decay']);
            $this->record($audit, $user, 'AuthStepUpFailed');

            throw ValidationException::withMessages(['password' => [__('platform::auth.password_incorrect')]]);
        }

        // Spent attempts are forgiven on success, as a login's are: the budget exists to slow guessing,
        // and somebody who has just proved the password was not guessing.
        RateLimiter::clear($budget['key']);
        $this->record($audit, $user, 'AuthStepUpSucceeded');
    }

    /**
     * @return array{key: string, attempts: int, decay: int}
     */
    private function passwordBudget(Request $request, User $user): array
    {
        $attempts = config('identity.rate_limits.step_up_password_per_user.max_attempts');
        $decay = config('identity.rate_limits.step_up_password_per_user.decay_seconds');

        return [
            // Per account *and* per address, the way every other authenticated budget in this package
            // is keyed — see `IdentityServiceProvider::registerRateLimiters()`.
            'key' => 'step-up-password|' . $user->id . '|' . (string) $request->ip(),
            // The shipped defaults, used when the config is missing or not an integer. Falling back
            // rather than failing closed: a mistyped setting must not lock every account out of a
            // gate it is the only way through, which is `StepUpPolicy::windowSeconds()`'s own rule.
            'attempts' => is_int($attempts) && $attempts > 0 ? $attempts : 5,
            'decay' => is_int($decay) && $decay > 0 ? $decay : 300,
        ];
    }

    private function record(AuditChainService $audit, User $user, string $eventType): void
    {
        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: $eventType,
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $audit->hashPayload(['factor' => StepUpFactor::PASSWORD->value]),
        ));
    }
}
