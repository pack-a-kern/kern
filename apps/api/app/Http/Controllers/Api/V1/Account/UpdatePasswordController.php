<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `PUT /api/v1/me/password` — changes the signed-in user's password.
 *
 * The current password is required even though the session is already authenticated: a session
 * left open on a shared machine is exactly the case this protects against, and without the check
 * whoever finds it can lock the owner out permanently.
 *
 * Other sessions are *not* revoked here. That is a deliberate choice for this application: a
 * password change is often routine hygiene, and silently signing someone out of their phone
 * mid-upload is worse than the marginal risk. Revoking sessions is its own, visible control on the
 * security screen, and the audit event below makes the change reviewable either way.
 */
final class UpdatePasswordController extends Controller
{
    public function __invoke(Request $request, AuditChainService $audit): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // Length over composition: NIST SP 800-63B, and `uncompromised()` checks the value
            // against known breach corpora rather than asking for a symbol nobody remembers.
            'password' => ['required', 'confirmed', Password::min(12)->uncompromised()],
        ]);

        $current = (string) $user->password_hash;

        if ($current === '' || ! Hash::check((string) $validated['current_password'], $current)) {
            // Attributed to `current_password` so the form marks the right field, and phrased so it
            // never distinguishes "wrong password" from "no password set" (a passkey-only account).
            throw ValidationException::withMessages([
                'current_password' => [__('The current password is incorrect.')],
            ]);
        }

        $user->password_hash = Hash::make((string) $validated['password']);
        $user->save();

        $userKey = $user->getKey();

        $audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: is_scalar($userKey) ? (string) $userKey : null,
            eventType: 'PASSWORD_CHANGED',
            resourceType: 'user',
            resourceId: is_scalar($userKey) ? (string) $userKey : '',
            // The hash of an empty payload: the event records *that* it happened and by whom.
            // Nothing about a password, old or new, belongs in an audit trail.
            payloadHash: $audit->hashPayload([]),
        ));

        return response()->json(['updated' => true]);
    }
}
