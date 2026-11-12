<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Exceptions\TooManyActiveSubjectsException;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;

/**
 * How many taxpayers one account may register, and how many of them may be in use at once.
 *
 * Registered: per platform role rather than one number for everyone — a private person needs one
 * taxpayer and an accountant needs fifty, so a single cap is either useless to the first or an
 * obstacle to the second. The roles are the ones the platform already has — this deliberately does
 * not invent a plan or tier concept, which would be a pricing decision wearing a config file's clothes.
 *
 * In use: one number for everyone (`SUBJECTS_MAX_ACTIVE`). Each active taxpayer is named in the
 * switcher on every page, each name decrypted under its own key; a closed one is not, and does not
 * count.
 *
 * Only *owned* taxpayers count. Being given access to someone else's books is not what either limit
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
        $configured = $this->config->get('subjects.limits.per_role');
        /** @var array<array-key, mixed> $limits */
        $limits = is_array($configured) ? $configured : [];

        $allowed = [$this->whole('subjects.limits.default', 'SUBJECTS_LIMIT_DEFAULT')];

        // The most generous role wins. Someone who is both an accountant and an administrator is
        // not thereby restricted to whichever role the array happened to list first.
        foreach ($roles as $role) {
            if (array_key_exists($role, $limits)) {
                $allowed[] = $this->whole("subjects.limits.per_role.{$role}", "SUBJECTS_LIMIT_{$role}");
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

    /**
     * Refuses one more taxpayer in use. Inside the caller's transaction: the owner's memberships are
     * locked while counted, so two requests at once cannot both take the last place.
     *
     * @throws TooManyActiveSubjectsException
     */
    public function ensureActiveRoom(string $userId): void
    {
        $owned = SubjectMembership::query()->where('user_id', $userId)->owned()->lockForUpdate()->pluck('subject_id');
        $max = $this->whole('subjects.limits.max_active', 'SUBJECTS_MAX_ACTIVE');

        if (TaxSubject::query()->whereIn('id', $owned)->where('status', SubjectStatus::ACTIVE)->count() >= $max) {
            throw TooManyActiveSubjectsException::of($max);
        }
    }

    /** @return positive-int */
    private function whole(string $key, string $variable): int
    {
        $value = filter_var($this->config->get($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($value) ? $value : throw new RuntimeException("{$variable} must be a whole number above zero.");
    }
}
