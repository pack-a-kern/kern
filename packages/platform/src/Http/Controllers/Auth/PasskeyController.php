<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Actions\RemovePasskey;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;

/**
 * `GET /api/v1/auth/passkeys` and `DELETE /api/v1/auth/passkeys/{passkeyId}` — the signed-in
 * account's own passkeys, and taking one away.
 *
 * The list carries what a person can recognise a passkey by and nothing a login could use: no
 * credential id, no public key. `synced` is the authenticator's own backup flag — a passkey kept in a
 * password manager or a phone's cloud keychain, rather than on one device only.
 */
final class PasskeyController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $passkeys = UserPasskey::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (UserPasskey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'synced' => $passkey->backup_status === true,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['passkeys' => $passkeys]);
    }

    public function destroy(Request $request, string $passkeyId, RemovePasskey $remove): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $remove($user, $passkeyId);

        return response()->json(null, 204);
    }
}
