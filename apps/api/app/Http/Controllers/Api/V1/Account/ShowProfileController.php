<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Enums\UserStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me` — the signed-in user's own account.
 *
 * Deliberately thin. This project stores almost nothing *about* a person on `users`: the email
 * address, a password hash, a status. Names, CNP and addresses live on `pf_profiles`, encrypted
 * with the tax subject's own key, because they are properties of a taxpayer rather than of a login
 * (`BLUEPRINT.md` §4.3, §6.2). So a profile screen here shows the account, and the tax subjects
 * screen shows the people — which is also why there is no avatar and no display name to edit.
 */
final class ShowProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        // `getKey()` is `mixed` to static analysis; the model declares a string key.
        $key = $user->getKey();

        return response()->json([
            'id' => is_scalar($key) ? (string) $key : '',
            'email' => (string) $user->email_ciphertext,
            // Read through `getAttribute` rather than the typed property: `User::createWithEmail()`
            // returns a model whose `status` has not been hydrated (the column's default is applied
            // by the database), so touching the typed property faults for an account that has just
            // registered. The property's declared type says otherwise, which is exactly why this
            // needs saying.
            'status' => $user->getAttribute('status') instanceof UserStatus
                ? $user->getAttribute('status')->value
                : null,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'has_password' => $user->password_hash !== null,
            'roles' => $user->getRoleNames()->all(),
        ]);
    }
}
