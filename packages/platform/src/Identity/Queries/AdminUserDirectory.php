<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Queries;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;

/**
 * The people who can sign in, for Administration → Users & roles.
 *
 * Email addresses are encrypted at rest (`BLUEPRINT.md` §4.3) and are decrypted here one row at a
 * time — there is no way to list users by name without doing so, and an administrator directory is
 * the one screen where that is the point. The listing is capped rather than paged for the same
 * reason: bulk-decrypting an unbounded number of rows to render a page is exactly the pattern §4.3
 * exists to prevent, and an installation large enough to need paging needs a search that does not
 * decrypt at all (a blind index), which is a different piece of work.
 */
final readonly class AdminUserDirectory
{
    private const int MAX_USERS = 200;

    /**
     * @return array{users: list<array<string, mixed>>, truncated: bool}
     */
    public function list(): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $users */
        $users = User::query()
            ->orderBy('created_at')
            ->limit(self::MAX_USERS + 1)
            ->get();

        $truncated = $users->count() > self::MAX_USERS;
        $users = $users->take(self::MAX_USERS);

        /** @var list<string> $ids */
        $ids = $users->map(fn (User $user): string => $this->idOf($user))->values()->all();

        $passkeys = $this->countBy('user_passkeys', $ids);
        $totp = $this->confirmedTotpUserIds($ids);
        $lastSeen = $this->lastSeenAt($ids);
        $memberships = $this->membershipsByUser($ids);
        $platformRoles = $this->platformRolesByUser($ids);

        /** @var list<array<string, mixed>> $rows */
        $rows = $users->map(function (User $user) use ($passkeys, $totp, $lastSeen, $memberships, $platformRoles): array {
            $id = $this->idOf($user);

            return [
                'id' => $id,
                'email' => (string) $user->email_ciphertext,
                'status' => (string) $user->status->value,
                'created_at' => $user->created_at?->toIso8601String(),
                'passkeys' => $passkeys[$id] ?? 0,
                'totp' => in_array($id, $totp, true),
                'last_seen_at' => $lastSeen[$id] ?? null,
                'memberships' => $memberships[$id] ?? [],
                'roles' => $platformRoles[$id] ?? [],
            ];
        })->values()->all();

        return ['users' => $rows, 'truncated' => $truncated];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function countBy(string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->intMap(
            DB::table($table)
                ->whereIn('user_id', $ids)
                ->groupBy('user_id')
                ->selectRaw('user_id, count(*) as total')
                ->pluck('total', 'user_id')
                ->all(),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function confirmedTotpUserIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Only a *confirmed* method counts: an unconfirmed row is a setup that was started and
        // abandoned, and reporting it as MFA coverage would overstate the security posture.
        return array_values(array_filter(array_map(
            static fn (mixed $id): ?string => is_scalar($id) ? (string) $id : null,
            DB::table('user_mfa_methods')
                ->whereIn('user_id', $ids)
                ->whereNotNull('confirmed_at')
                ->distinct()
                ->pluck('user_id')
                ->all(),
        )));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function lastSeenAt(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->stringMap(
            DB::table('user_sessions')
                ->whereIn('user_id', $ids)
                ->whereNull('revoked_at')
                ->groupBy('user_id')
                ->selectRaw('user_id, max(last_used_at) as seen')
                ->pluck('seen', 'user_id')
                ->all(),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function membershipsByUser(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('subject_memberships')
            ->whereIn('user_id', $ids)
            ->whereNull('revoked_at')
            ->get(['user_id', 'role']);

        $byUser = [];

        foreach ($rows as $row) {
            $fields = (array) $row;
            $userId = $fields['user_id'] ?? null;
            $role = $fields['role'] ?? null;

            if (is_scalar($userId) && is_scalar($role)) {
                $byUser[(string) $userId][] = (string) $role;
            }
        }

        return $byUser;
    }

    /**
     * Platform roles (`config/access.php`), as opposed to the per-tax-subject memberships above.
     * Read through the pivot rather than by hydrating every user's relation, which would be one
     * query per row.
     *
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function platformRolesByUser(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->whereIn('mhr.model_id', $ids)
            ->where('mhr.model_type', User::class)
            ->get(['mhr.model_id', 'r.name']);

        $byUser = [];

        foreach ($rows as $row) {
            $fields = (array) $row;
            $userId = $fields['model_id'] ?? null;
            $name = $fields['name'] ?? null;

            if (is_scalar($userId) && is_scalar($name)) {
                $byUser[(string) $userId][] = (string) $name;
            }
        }

        return $byUser;
    }

    /** `getKey()` is `mixed` to static analysis; the model declares a string key. */
    private function idOf(User $user): string
    {
        $key = $user->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * `pluck()` is typed as `mixed`; these narrow a keyed result once, at the boundary, instead of
     * casting at every use.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    private function intMap(array $rows): array
    {
        $out = [];

        foreach ($rows as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, string>
     */
    private function stringMap(array $rows): array
    {
        $out = [];

        foreach ($rows as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
