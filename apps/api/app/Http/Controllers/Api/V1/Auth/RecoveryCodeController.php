<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\GenerateRecoveryCodes;
use App\Domain\Identity\Exceptions\TotpNotEnrolledException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/auth/mfa/recovery-codes/regenerate` — behind `auth:web`.
 */
final class RecoveryCodeController extends Controller
{
    public function regenerate(Request $request, GenerateRecoveryCodes $generate): JsonResponse
    {
        /** @var \App\Domain\Identity\Models\User $user */
        $user = $request->user();

        try {
            $codes = $generate($user);
        } catch (TotpNotEnrolledException $exception) {
            throw ValidationException::withMessages(['totp' => [$exception->getMessage()]]);
        }

        return response()->json(['recovery_codes' => $codes]);
    }
}
