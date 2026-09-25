<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Enums\MfaMethodType;

/**
 * `GET /api/v1/auth/mfa/status` — behind `auth:web`. `recovery_codes_remaining` is what
 * `BLUEPRINT.md` §4.7's "countable so the UI can show how many remain" asks for.
 */
final class MfaStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        $totpEnrolled = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();

        return response()->json([
            'totp_enrolled' => $totpEnrolled,
            'passkeys_count' => $user->passkeys()->count(),
            'recovery_codes_remaining' => $user->recoveryCodes()->whereNull('used_at')->count(),
        ]);
    }
}
