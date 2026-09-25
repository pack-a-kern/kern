<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Platform\Http\Controllers\Account\ShowProfileController;
use Kern\Platform\Http\Controllers\Account\UpdatePasswordController;
use Kern\Platform\Http\Controllers\Admin\CreateUserController;
use Kern\Platform\Http\Controllers\Admin\EraseUserController;
use Kern\Platform\Http\Controllers\Admin\ImpersonationController;
use Kern\Platform\Http\Controllers\Admin\ShowAccessCatalogueController;
use Kern\Platform\Http\Controllers\Admin\UpdateUserRolesController;
use Kern\Platform\Http\Controllers\Admin\UpdateUserStatusController;
use Kern\Platform\Http\Controllers\Auth\DisableTotpController;
use Kern\Platform\Http\Controllers\Auth\LoginController;
use Kern\Platform\Http\Controllers\Auth\LogoutController;
use Kern\Platform\Http\Controllers\Auth\MfaChallengeController;
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
        Route::post('logout', LogoutController::class);

        Route::post('passkeys/registration/options', [PasskeyRegistrationController::class, 'options'])
            ->middleware('throttle:passkey-ceremony-per-ip');
        Route::post('passkeys/registration', [PasskeyRegistrationController::class, 'store'])
            ->middleware('throttle:passkey-ceremony-per-ip');

        Route::get('mfa/status', MfaStatusController::class);
        Route::post('mfa/totp/enroll', [TotpController::class, 'enroll']);
        Route::post('mfa/totp/confirm', [TotpController::class, 'confirm']);
        Route::delete('mfa/totp', DisableTotpController::class);
        Route::post('mfa/recovery-codes/regenerate', [RecoveryCodeController::class, 'regenerate']);

        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy']);
        Route::delete('sessions', [SessionController::class, 'destroyAll']);
    });
});

/*
 * `docs/handoff/031-pwa-backend-endpoints.md` — which `TaxSubject`s the authenticated user can
 * access (driven by `subject_memberships`, `docs/handoff/011-identity-tenancy.md`), and
 * registering a new PF one (`App\Domain\Subjects\Actions\RegisterPfSubject`, reused verbatim).
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
        Route::put('users/{user}/roles', UpdateUserRolesController::class);
        Route::put('users/{user}/status', UpdateUserStatusController::class);
        // Erasing a person and acting as one are not role changes: both check SUPER_ADMIN
        // themselves, on top of the `users.manage` permission this group already requires.
        Route::delete('users/{user}', EraseUserController::class);
        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start']);
    });
});

// The signed-in user's own account. No permission beyond being authenticated: everything here is
// scoped to `$request->user()` and can only ever read or change that one account.
Route::prefix('v1/me')->middleware('auth:web')->group(function (): void {
    Route::get('/', ShowProfileController::class);
    Route::put('password', UpdatePasswordController::class);
});
