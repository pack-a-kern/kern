<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Enums\MfaMethodType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/auth/mfa/status` — behind `auth:web`. `recovery_codes_remaining` is what
 * `BLUEPRINT.md` §4.7's "countable so the UI can show how many remain" asks for.
 */
final class MfaStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Domain\Identity\Models\User $user */
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
