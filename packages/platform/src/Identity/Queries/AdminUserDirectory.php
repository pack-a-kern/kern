<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Identity\DataTransferObjects\UserDirectoryFilter;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\Contracts\TenantMemberships;

/**
 * The people who can sign in, for Administration → Users & roles: one page of them, and the counts
 * over every account.
 *
 * Status, role and paging are SQL. An email address is encrypted at rest (`BLUEPRINT.md` §4.3), so
 * it is looked for whole, through its blind index, never by part: that would mean decrypting every
 * account. The rows of the page are the only addresses decrypted.
 */
final readonly class AdminUserDirectory
{
    public function __construct(private TenantMemberships $memberships, private BlindIndexHasher $hasher)
    {
    }

    /**
     * @return array{users: list<array<string, mixed>>, total: int, page: int, per_page: int, stats: array{users: int, with_mfa: int, roles: array<string, int>}}
     */
    public function page(UserDirectoryFilter $filter): array
    {
        $matching = $this->matching($filter);
        $total = $matching->count();
        // A page past the end — the last row of it erased, a filter narrowed — is the last one there is.
        $page = min($filter->page, max(1, (int) ceil($total / $filter->perPage)));

        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $users */
        $users = $matching
            ->orderBy('created_at')
            ->orderBy('id')
            ->forPage($page, $filter->perPage)
            ->get();

        /** @var list<string> $ids */
        $ids = $users->map(fn (User $user): string => $this->idOf($user))->values()->all();

        $passkeys = $this->countBy('user_passkeys', $ids);
        $totp = $this->confirmedTotpUserIds($ids);
        $lastSeen = $this->lastSeenAt($ids);
        $memberships = $this->memberships->rolesByUser($ids);
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
                /*
                 * The timestamp, not a URL.
                 *
                 * Null means initials; anything else means there is a picture, and the value is what
                 * makes each new upload a new URL so a browser stops showing the old one. Building the
                 * URL here would bake this package's route prefix into a payload, which is the screen's
                 * business rather than the directory's.
                 */
                'avatar_updated_at' => $user->avatar_updated_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'users' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $filter->perPage,
            'stats' => [
                'users' => User::query()->count(),
                'with_mfa' => User::query()
                    ->where(fn (Builder $query) => $query
                        ->whereExists(fn (QueryBuilder $passkeys) => $passkeys->from('user_passkeys')->whereColumn('user_passkeys.user_id', 'users.id'))
                        ->orWhereExists(fn (QueryBuilder $totp) => $totp->from('user_mfa_methods')->whereColumn('user_mfa_methods.user_id', 'users.id')->whereNotNull('confirmed_at')))
                    ->count(),
                'roles' => $this->intMap($this->platformRoles()->groupBy('r.name')->selectRaw('r.name, count(*) as total')->pluck('total', 'name')->all()),
            ],
        ];
    }

    /** @return Builder<User> */
    private function matching(UserDirectoryFilter $filter): Builder
    {
        return User::query()
            ->when($filter->email !== null, fn (Builder $query) => $query->where(
                'email_blind_index',
                $this->hasher->compute(Normalizer::email((string) $filter->email)),
            ))
            ->when($filter->status !== null, fn (Builder $query) => $query->where('status', $filter->status))
            ->when($filter->role !== null, fn (Builder $query) => $query->whereIn(
                'id',
                $this->platformRoles()->where('r.name', $filter->role)->select('mhr.model_id'),
            ));
    }

    /** Every platform role held, one row per user and role. */
    private function platformRoles(): QueryBuilder
    {
        return DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            // The alias the platform registers, not the class name: see PlatformServiceProvider.
            ->where('mhr.model_type', (new User())->getMorphClass());
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
     * Platform roles (`config/access.php`), as opposed to the per-tenant memberships
     * {@see TenantMemberships} answers. Read through the pivot rather than by hydrating every user's
     * relation, which would be one query per row.
     *
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private function platformRolesByUser(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->platformRoles()->whereIn('mhr.model_id', $ids)->get(['mhr.model_id', 'r.name']);

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
