<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Exceptions\EmailAlreadyRegisteredException;
use Kern\Platform\Identity\Models\User;

/**
 * Creates a new `User` — the account/identity row, not a `TaxSubject` (`Kern\TaxRomania\Subjects\
 * Actions\RegisterPfSubject` is a separate, later step once the user picks a fiscal identity to
 * register, per `docs/handoff/011-identity-tenancy.md`).
 *
 * Takes the password already hashed: the request that carried it hashes it before handing the rest
 * to `Jobs\RegisterAccount`, so the plain password never leaves that request. `$passwordHash` is
 * nullable — a passkey-only account is explicitly allowed (`BLUEPRINT.md` §5.1); a caller registering
 * passkey-only passes `null`, then follows with `BeginPasskeyRegistration`/`CompletePasskeyRegistration`.
 *
 * The duplicate-email pre-check races the same way `Kern\TaxRomania\Subjects\Actions\
 * RegisterPfSubject`'s duplicate-CNP check does (documented there as a known, accepted gap) — a
 * genuine concurrent registration of the same email is still blocked, just by the raw
 * `users.email_blind_index` unique constraint rather than this friendly exception.
 */
final readonly class RegisterUser
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(string $email, ?string $passwordHash): User
    {
        if (User::query()->whereEmail($email)->exists()) {
            throw EmailAlreadyRegisteredException::create();
        }

        $user = User::createWithEmail($email, $passwordHash);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'UserRegistered',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['has_password' => $passwordHash !== null]),
        ));

        return $user;
    }
}
