<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kern\Platform\Identity\Actions\GenerateRecoveryCodes;
use Kern\Platform\Identity\Exceptions\TotpNotEnrolledException;

/**
 * `POST /api/v1/auth/mfa/recovery-codes/regenerate` — behind `auth:web`.
 */
final class RecoveryCodeController
{
    public function regenerate(Request $request, GenerateRecoveryCodes $generate): JsonResponse
    {
        /** @var \Kern\Platform\Identity\Models\User $user */
        $user = $request->user();

        try {
            $codes = $generate($user);
        } catch (TotpNotEnrolledException $exception) {
            throw ValidationException::withMessages(['totp' => [$exception->getMessage()]]);
        }

        return response()->json(['recovery_codes' => $codes]);
    }
}
