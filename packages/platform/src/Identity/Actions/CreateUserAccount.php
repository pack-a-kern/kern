<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Exceptions\EmailAlreadyRegisteredException;
use Kern\Platform\Identity\Models\User;

/**
 * An account opened by an administrator, with a password nobody chose.
 *
 * ## Why the password is generated
 *
 * The alternative is an administrator typing one, and an administrator typing one types the same
 * one for everybody. Generated means the credential is strong by construction, and that the
 * administrator never learns a password the person might also be using elsewhere — they hand over a
 * string neither of them has a reason to remember.
 *
 * It is returned **once**, by this action, and never again: only its hash is stored, so no screen
 * and no query can recover it afterwards. A lost handover needs a new password, not a lookup.
 */
final readonly class CreateUserAccount
{
    public function __construct(private AuditChainService $audit)
    {
    }

    /**
     * @param  list<string>  $roles
     * @return array{user: User, password: string}
     */
    public function __invoke(string $email, array $roles, string $actorId): array
    {
        if (User::query()->whereEmail($email)->exists()) {
            throw EmailAlreadyRegisteredException::create();
        }

        // Laravel's own generator: letters, digits and symbols, from a cryptographic source.
        $password = Str::password(16);

        $user = DB::transaction(function () use ($email, $roles, $password, $actorId): User {
            $user = User::createWithEmail($email, Hash::make($password));

            if ($roles !== []) {
                $user->syncRoles($roles);
            }

            // The roles are named; the password is not, and never will be — an audit entry
            // carrying it would be a permanent copy of a live credential.
            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: $actorId,
                eventType: 'UserCreatedByAdministrator',
                resourceType: 'User',
                resourceId: $user->id,
                payloadHash: $this->audit->hashPayload(['user_id' => $user->id, 'roles' => $roles]),
            ));

            return $user;
        });

        return ['user' => $user, 'password' => $password];
    }
}
