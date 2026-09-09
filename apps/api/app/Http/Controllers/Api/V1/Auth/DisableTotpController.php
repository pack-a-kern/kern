<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\Enums\MfaMethodType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * `DELETE /api/v1/auth/mfa/totp` — removes the authenticator app from this account.
 *
 * Two properties this endpoint has to get right:
 *
 * **Re-authentication.** An authenticated session is not enough. Stripping a second factor is
 * precisely what someone who has stolen a session wants to do, so the current password is required
 * again here. An account with no password authenticates by passkey, and that passkey survives this
 * operation — so there is nothing to re-verify and none is demanded.
 *
 * **Recovery codes go with it.** `App\Domain\Identity\Actions\CompleteMfaChallenge` matches a
 * submitted code against unused recovery codes *independently* of TOTP: leaving them behind would
 * leave working second factors on an account whose owner has just been told the second factor is
 * gone. They are deleted in the same transaction.
 */
final class DisableTotpController extends Controller
{
    public function __invoke(Request $request, AuditChainService $audit): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $currentHash = $user->password_hash;

        if ($currentHash !== null && $currentHash !== '') {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
            ]);

            if (! Hash::check((string) $validated['current_password'], $currentHash)) {
                throw ValidationException::withMessages([
                    'current_password' => [__('The current password is incorrect.')],
                ]);
            }
        }

        $enrolled = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();

        if (! $enrolled) {
            throw ValidationException::withMessages([
                'totp' => [__('This account has no confirmed authenticator app enrolled.')],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $user->mfaMethods()->where('type', MfaMethodType::TOTP)->delete();
            $user->recoveryCodes()->whereNull('used_at')->delete();
        });

        $key = $user->getKey();
        $userId = is_scalar($key) ? (string) $key : '';

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $userId,
            eventType: 'MFA_TOTP_DISABLED',
            resourceType: 'user',
            resourceId: $userId,
            payloadHash: $audit->hashPayload([]),
        ));

        return response()->json(['disabled' => true]);
    }
}
