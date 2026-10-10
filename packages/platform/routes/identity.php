<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Platform\Http\Controllers\Account\ShowProfileController;
use Kern\Platform\Http\Controllers\Account\UpdatePasswordController;
use Kern\Platform\Http\Controllers\Admin\CreateUserController;
use Kern\Platform\Http\Controllers\Admin\EraseUserController;
use Kern\Platform\Http\Controllers\Admin\ImpersonationController;
use Kern\Platform\Http\Controllers\Admin\ShowAccessCatalogueController;
use Kern\Platform\Http\Controllers\Admin\UpdateUserAvatarController;
use Kern\Platform\Http\Controllers\Admin\UpdateUserRolesController;
use Kern\Platform\Http\Controllers\Admin\UpdateUserStatusController;
use Kern\Platform\Http\Controllers\ShowUserAvatarController;
use Kern\Platform\Http\Controllers\Auth\DisableTotpController;
use Kern\Platform\Http\Controllers\Auth\EmailVerificationController;
use Kern\Platform\Http\Controllers\Auth\LoginController;
use Kern\Platform\Http\Controllers\Auth\LogoutController;
use Kern\Platform\Http\Controllers\Auth\MfaChallengeController;
use Kern\Platform\Http\Controllers\Auth\StepUpVerificationController;
use Kern\Platform\Http\Controllers\Auth\MfaStatusController;
use Kern\Platform\Http\Controllers\Auth\PasskeyLoginController;
use Kern\Platform\Http\Controllers\Auth\PasskeyRegistrationController;
use Kern\Platform\Http\Controllers\Auth\RecoveryCodeController;
use Kern\Platform\Http\Controllers\Auth\RegistrationController;
use Kern\Platform\Http\Controllers\Auth\SessionController;
use Kern\Platform\Http\Controllers\Auth\TotpController;
use Kern\Platform\Http\Controllers\Notifications\ListNotificationsController;
use Kern\Platform\Http\Controllers\Notifications\MarkNotificationsReadController;

/*
|--------------------------------------------------------------------------
| Identity — JSON routes
|--------------------------------------------------------------------------
|
| Signing in, proving a second factor, listing and revoking one's own sessions, administering
| accounts, and the notification bell. Loaded by `Kern\Platform\Identity\IdentityServiceProvider`
| under the prefix and middleware `config('kern.routing')` names.
|
| The `throttle:` limiters these routes name are defined in that provider; `permission:` and
| `auth:web` are the application's, as they are for every package route file.
*/

/*
 * `docs/handoff/017-authentication.md` documents the full lifecycle these routes implement.
 * Every unauthenticated authentication endpoint carries a named rate limiter
 * (`Kern\Platform\Identity\IdentityServiceProvider::boot()`, `BLUEPRINT.md` §4.7) — none rely on the
 * framework's default unkeyed `throttle:60,1`.
 */
Route::prefix('v1/auth')->group(function (): void {
    Route::post('register', RegistrationController::class)
        ->middleware('throttle:register-per-ip');

    Route::post('login', LoginController::class)
        ->middleware(['throttle:login-per-account-ip', 'throttle:login-per-ip']);

    Route::post('mfa/challenge', MfaChallengeController::class)
        ->middleware('throttle:mfa-challenge-per-user');

    Route::post('passkeys/login/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:passkey-ceremony-per-ip');
    Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])
        ->middleware('throttle:passkey-ceremony-per-ip');

    Route::middleware('auth:web')->group(function (): void {
        Route::post('logout', LogoutController::class)->name('auth.logout');

        /*
         * The three routes an account with an unverified address may still reach — named, because
         * `Kern\Platform\Identity\Http\Middleware\RequireVerifiedEmail` allows them by name rather
         * than by path. Held on a screen with no way to leave it is how this feature goes wrong, so
         * `auth.logout` above carries a name for the same reason.
         *
         * `status` is free and the other two are throttled: reading where you stand should not cost
         * anything, submitting a code must not be repeatable a million times, and asking for another
         * must not turn somebody's inbox into a weapon.
         */
        Route::get('email/verification', [EmailVerificationController::class, 'status'])
            ->name('auth.email.status');
        Route::post('email/verify', [EmailVerificationController::class, 'verify'])
            ->name('auth.email.verify')
            ->middleware('throttle:email-verify-per-user');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->name('auth.email.resend')
            ->middleware('throttle:email-resend-per-user');

        Route::post('passkeys/registration/options', [PasskeyRegistrationController::class, 'options'])
            ->middleware('throttle:passkey-ceremony-per-ip');
        Route::post('passkeys/registration', [PasskeyRegistrationController::class, 'store'])
            ->middleware('throttle:passkey-ceremony-per-ip');

        /*
         * Proving the second factor again inside a live session, for whatever
         * `RequiresRecentVerification` is guarding. Deliberately not itself guarded by that
         * middleware: a route that demanded recent verification in order to verify could never be
         * satisfied.
         */
        Route::post('step-up', StepUpVerificationController::class)
            ->middleware('throttle:step-up-per-user');

        Route::get('mfa/status', MfaStatusController::class);
        Route::post('mfa/totp/enroll', [TotpController::class, 'enroll']);
        Route::post('mfa/totp/confirm', [TotpController::class, 'confirm']);
        Route::delete('mfa/totp', DisableTotpController::class);
        Route::post('mfa/recovery-codes/regenerate', [RecoveryCodeController::class, 'regenerate']);

        /*
         * Listing is open; ending is not.
         *
         * Throwing a device off the account is the move somebody makes with a session they have taken
         * — lock the owner out, keep the one they hold — and it is equally the move the owner makes to
         * throw *them* off. Both arrive as the same request, and the only thing that separates them is
         * whether the sender can produce the account's secret. That is exactly what this gate asks.
         *
         * It is answerable by every account that exists, which is what made it possible to put here:
         * before the password factor, an account with no authenticator app would have been refused by
         * a dialog it could not satisfy, and the way to end a stolen session would have been to enrol
         * TOTP first, on the compromised session, while being watched.
         *
         * Signing yourself out is `POST auth/logout` and stays ungated — leaving must never need a
         * password, and nothing is taken from anyone by it.
         */
        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy'])
            ->middleware('verify-recent');
        Route::delete('sessions', [SessionController::class, 'destroyAll'])
            ->middleware('verify-recent');
    });
});

/*
 * `docs/handoff/031-pwa-backend-endpoints.md` — which `TaxSubject`s the authenticated user can
 * access (driven by `subject_memberships`, `docs/handoff/011-identity-tenancy.md`), and
 * registering a new PF one (`Kern\TaxRomania\Subjects\Actions\RegisterPfSubject`, reused verbatim).
 * Neither route binds `{subject}`/`tax-subject` — listing must work *before* any subject is
 * chosen, and registration creates one.
 */
Route::post('v1/admin/impersonation/stop', [ImpersonationController::class, 'stop'])->middleware('auth:web');

/*
 * The bell: a user's own notices, whichever taxpayer each is about. Not under `v1/subjects/{subject}`
 * because a notice follows the person, not the subject selected when the menu is opened.
 */
Route::prefix('v1/notifications')->middleware('auth:web')->group(function (): void {
    Route::get('/', ListNotificationsController::class);
    Route::post('read', MarkNotificationsReadController::class);
});

// User administration. Same gate as settings and audit: this lists every account on the
// installation, with decrypted email addresses.
Route::prefix('v1/admin')->middleware(['auth:web'])->group(function (): void {
    // `GET admin/users` is not here: it reports how many memberships hold each tenant role
    // alongside each account, and what a membership is belongs to the application. It is registered
    // in `routes/api.php` beside the other compositions.
    Route::middleware('permission:users.view')->group(function (): void {
        Route::get('access', ShowAccessCatalogueController::class);
    });

    Route::middleware('permission:users.manage')->group(function (): void {
        Route::post('users', CreateUserController::class);
        Route::post('users/{user}/avatar', [UpdateUserAvatarController::class, 'store']);
        Route::delete('users/{user}/avatar', [UpdateUserAvatarController::class, 'destroy']);
        Route::put('users/{user}/roles', UpdateUserRolesController::class);
        Route::put('users/{user}/status', UpdateUserStatusController::class);
        // Erasing a person and acting as one are not role changes: both check SUPER_ADMIN
        // themselves, on top of the `users.manage` permission this group already requires.
        Route::delete('users/{user}', EraseUserController::class);
        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start']);
    });
});

/*
 * A user's picture, read by the screens that list people.
 *
 * Outside the `v1/admin` group on purpose: everybody can see their own, which is the common case and
 * needs no administrative permission at all. The controller decides — your own always, anybody else's
 * with `users.view` — because the rule depends on which user is being asked for, and route middleware
 * cannot see that.
 */
Route::get('v1/users/{user}/avatar', ShowUserAvatarController::class)->middleware('auth:web');

// The signed-in user's own account. No permission beyond being authenticated: everything here is
// scoped to `$request->user()` and can only ever read or change that one account.
Route::prefix('v1/me')->middleware('auth:web')->group(function (): void {
    Route::get('/', ShowProfileController::class);
    Route::put('password', UpdatePasswordController::class);
});
