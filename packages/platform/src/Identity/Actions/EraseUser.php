<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\Models\NotificationPreference;
use Kern\Platform\Notifications\Models\PushSubscription;
use Kern\Platform\Tenancy\Contracts\TenantMemberships;

/**
 * Erases a person from this installation, as far as the record permits.
 *
 * ## Why this is not a DELETE
 *
 * `audit_events.actor_id` is `ON DELETE RESTRICT`, and the table carries a trigger refusing DELETE
 * outright. That is deliberate: the chain exists so that nobody — an administrator included, this
 * code included — can make it say that something never happened. A user who has ever acted
 * therefore cannot be removed, and pretending otherwise would mean either a button that fails or an
 * audit chain with holes in it.
 *
 * So erasure means everything *except* the trail. What survives is "user 01a0… did this, then";
 * what does not is any way to learn who that was, or to sign in as them again.
 *
 * | thing | what happens |
 * |---|---|
 * | sessions | deleted — signed out everywhere, at once |
 * | notification devices and choices | deleted — no device of theirs is sent anything again |
 * | passkeys, authenticator, recovery codes | deleted — nothing can authenticate as them again |
 * | memberships | deleted — access to every taxpayer ends |
 * | platform roles and permissions | removed — a tombstone that still held `SUPER_ADMIN` counted as one, so the guard on removing the last super administrator counted it too |
 * | email, password | replaced by a tombstone; the blind index is randomised, so the address can neither be found by searching for it nor collide with a later registration |
 * | status | `DELETED` |
 */
final readonly class EraseUser
{
    public function __construct(private AuditChainService $audit, private TenantMemberships $memberships)
    {
    }

    public function __invoke(User $user, string $actorId): void
    {
        DB::transaction(function () use ($user, $actorId): void {
            $user->sessions()->delete();
            // Its devices stop receiving notifications, and its choices of which go with them.
            PushSubscription::query()->where('user_id', $user->id)->delete();
            NotificationPreference::query()->where('user_id', $user->id)->delete();
            $user->passkeys()->delete();
            $user->mfaMethods()->delete();
            $user->recoveryCodes()->delete();

            // Memberships are the application's, so the application ends them.
            $this->memberships->removeAllFor($user->id);

            $user->syncRoles([]);
            $user->syncPermissions([]);

            // A random index rather than null: the column is unique, and an index left intact would
            // still answer "is this address registered?" to anyone who cared to ask.
            $user->email_ciphertext = sprintf('erased-%s@invalid', Str::uuid()->toString());
            $user->email_blind_index = hash('sha256', Str::uuid()->toString());
            $user->password_hash = null;
            $user->email_verified_at = null;
            $user->status = UserStatus::DELETED;
            $user->save();

            // Account-level: an erasure is about a person rather than about any one taxpayer, so it
            // joins the chain that records logins and registrations.
            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: $actorId,
                eventType: 'UserErased',
                resourceType: 'User',
                resourceId: $user->id,
                payloadHash: $this->audit->hashPayload(['user_id' => $user->id]),
            ));
        });
    }
}
