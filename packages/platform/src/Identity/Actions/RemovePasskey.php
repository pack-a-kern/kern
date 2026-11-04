<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;

/**
 * Takes one passkey off an account, at its owner's request.
 *
 * Only the account's own passkeys can be named: another account's id is "not found", exactly like an
 * id that never existed, so the endpoint cannot be used to learn which passkeys exist. The route sits
 * behind `verify-recent`, as adding one does — a session alone must not be able to strip a factor.
 *
 * The last way in is never removed. An account with no password signs in by passkey alone, so its
 * last passkey is refused here: removing it would lock the owner out with no way back but an
 * administrator.
 */
final readonly class RemovePasskey
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(User $user, string $passkeyId): void
    {
        $passkey = UserPasskey::query()->where('user_id', $user->id)->whereKey($passkeyId)->first();

        if ($passkey === null) {
            throw (new ModelNotFoundException())->setModel(UserPasskey::class, [$passkeyId]);
        }

        $hasPassword = $user->password_hash !== null && $user->password_hash !== '';

        if (! $hasPassword && UserPasskey::query()->where('user_id', $user->id)->count() === 1) {
            throw ValidationException::withMessages([
                'passkey' => [__('platform::auth.last_passkey')],
            ]);
        }

        $passkey->delete();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'PasskeyRemoved',
            resourceType: 'UserPasskey',
            resourceId: $passkey->id,
            payloadHash: $this->audit->hashPayload(['aaguid' => $passkey->aaguid]),
        ));
    }
}
