<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;

/**
 * How many taxpayers one account may register, and how many it already has.
 *
 * Per platform role rather than one number for everyone: a private person needs one taxpayer and an
 * accountant needs fifty, so a single cap is either useless to the first or an obstacle to the
 * second. The roles are the ones the platform already has — this deliberately does not invent a
 * plan or tier concept, which would be a pricing decision wearing a config file's clothes.
 *
 * Only *owned* taxpayers count. Being given access to someone else's books is not what this limit
 * is about, and counting it would let one accountant's invitations exhaust a client's allowance.
 */
final readonly class SubjectAllowance
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @param list<string> $roles the platform roles the account carries */
    public function limitFor(array $roles): int
    {
        $configured = $this->config->get('subjects.limits.per_role', []);
        /** @var array<array-key, mixed> $limits */
        $limits = is_array($configured) ? $configured : [];

        $fallback = $this->config->get('subjects.limits.default', 1);
        $allowed = [is_numeric($fallback) ? (int) $fallback : 1];

        // The most generous role wins. Someone who is both an accountant and an administrator is
        // not thereby restricted to whichever role the array happened to list first.
        foreach ($roles as $role) {
            $limit = $limits[$role] ?? null;

            if (is_numeric($limit)) {
                $allowed[] = (int) $limit;
            }
        }

        return max($allowed);
    }

    public function ownedBy(string $userId): int
    {
        return SubjectMembership::query()->where('user_id', $userId)->owned()->count();
    }

    /** @param list<string> $roles */
    public function hasRoom(string $userId, array $roles): bool
    {
        return $this->ownedBy($userId) < $this->limitFor($roles);
    }
}
