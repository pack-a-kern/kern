<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\EmailAlreadyRegisteredException;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * Creates a new `User` — the account/identity row, not a `TaxSubject` (`App\Domain\Subjects\
 * Actions\RegisterPfSubject` is a separate, later step once the user picks a fiscal identity to
 * register, per `docs/handoff/011-identity-tenancy.md`).
 *
 * `$password` is nullable — a passkey-only account is explicitly allowed (`BLUEPRINT.md` §5.1); a
 * caller registering passkey-only still calls this action with `password: null`, then immediately
 * follows with `BeginPasskeyRegistration`/`CompletePasskeyRegistration`.
 *
 * The duplicate-email pre-check races the same way `App\Domain\Subjects\Actions\
 * RegisterPfSubject`'s duplicate-CNP check does (documented there as a known, accepted gap) — a
 * genuine concurrent registration of the same email is still blocked, just by the raw
 * `users.email_blind_index` unique constraint rather than this friendly exception.
 */
final readonly class RegisterUser
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(string $email, ?string $password): User
    {
        if (User::query()->whereEmail($email)->exists()) {
            throw EmailAlreadyRegisteredException::create();
        }

        $user = User::createWithEmail($email, $password !== null ? Hash::make($password) : null);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'UserRegistered',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['has_password' => $password !== null]),
        ));

        return $user;
    }
}
