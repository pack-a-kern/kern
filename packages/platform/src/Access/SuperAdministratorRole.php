<?php

declare(strict_types=1);

namespace Kern\Platform\Access;

use Illuminate\Http\JsonResponse;
use Kern\Platform\Identity\Enums\UserStatus;
use Kern\Platform\Identity\Models\User;

/**
 * Who may hand out or take away `SUPER_ADMIN` — one rule, for every screen that changes roles.
 *
 * `users.manage` opens the screens that create accounts and change their roles. It is SUPER_ADMIN's
 * alone in the shipped `config/access.php`, but it is a permission like any other and can be given to
 * another role; the role that may impersonate and erase people must not come with it. So whoever
 * holds `users.manage` may change every other role, and only a super administrator may change who is
 * one — granting it, to themselves or anyone, and removing it from someone who has it.
 *
 * It used to be checked when an account was created and not when its roles were changed, so the
 * second screen granted what the first refused.
 */
final class SuperAdministratorRole
{
    public const string NAME = 'SUPER_ADMIN';

    /**
     * Whether `$actor` may move an account from the roles `$before` to `$after` — always, unless the
     * move gives or takes `SUPER_ADMIN` and the actor is not one.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function allowsChange(User $actor, array $before, array $after): bool
    {
        $held = in_array(self::NAME, $before, true);
        $kept = in_array(self::NAME, $after, true);

        return $held === $kept || self::isHeldBy($actor);
    }

    /** Whether this account is a super administrator — the one way that is asked. */
    public static function isHeldBy(User $user): bool
    {
        return $user->hasRole(self::NAME);
    }

    /**
     * How many *active* accounts other than `$except` are super administrators — what "is this the last
     * one?" has to count. A suspended account cannot sign in to grant anything, and an erased one never
     * will, so neither is somebody left to administer the installation.
     */
    public static function activeHoldersOtherThan(User $except): int
    {
        return User::role(self::NAME)
            ->where('status', UserStatus::ACTIVE)
            ->whereKeyNot($except->getKey())
            ->count();
    }

    /** The refusal, with the reason the interface translates. */
    public static function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only a super administrator may grant or remove that role.',
            'reason' => 'SUPER_ADMIN_ONLY',
        ], 403);
    }
}
