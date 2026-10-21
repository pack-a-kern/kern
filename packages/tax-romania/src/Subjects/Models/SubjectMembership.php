<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;

/**
 * `subject_memberships` (`BLUEPRINT.md` §5.3) — the single source of truth for "which `User`s may
 * access which `TaxSubject`s, and with what role". `Kern\Platform\Tenancy\TenantContext`
 * is the only sanctioned way another module resolves this; nothing else in this codebase should
 * query this table directly to make an authorization decision.
 *
 * No `belongsTo` relationship to `Kern\Platform\Identity\Models\User` on purpose (`docs/AGENTS.md`
 * §6: a module never imports another module's Eloquent models) — `user_id` is a plain column.
 * No encrypted fields, so neither `HasUuidPrimaryKey`'s AAD-ordering concern nor
 * `FillsSubjectForeignKeyFirst` applies for that reason; `HasUuidPrimaryKey` is still used purely
 * for the UUIDv7-primary-key convention (`BLUEPRINT.md` §5/§6), not for any encryption requirement.
 *
 * @property string $id
 * @property string $subject_id
 * @property string $user_id
 * @property MembershipRole $role
 * @property array<string, mixed> $permissions_json
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class SubjectMembership extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'subject_id',
        'user_id',
        'role',
        'permissions_json',
        'valid_from',
        'revoked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'role' => MembershipRole::class,
        'permissions_json' => 'array',
        'valid_from' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Memberships in force: not revoked. A revoked one is history, not access — the one definition
     * every other scope, the gate and every count starts from.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Memberships that open their taxpayer: not revoked, and in a role that has rules
     * ({@see MembershipRole::opening()}). One definition for the gate and for every list of "my
     * taxpayers", so a membership that opens nothing is not shown as one that does.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpening(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereIn('role', array_map(static fn (MembershipRole $role): string => $role->value, MembershipRole::opening()));
    }

    /**
     * Memberships that own their taxpayer: not revoked, and OWNER — what an owner-only action asks
     * for (its status, its details, its picture), which stays the owner's whatever sharing adds.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwned(Builder $query): Builder
    {
        return $query->active()->where('role', MembershipRole::OWNER->value);
    }

    /** True when this user holds a membership that opens this taxpayer. */
    public static function opens(string $userId, string $subjectId): bool
    {
        return self::query()->where('user_id', $userId)->where('subject_id', $subjectId)->opening()->exists();
    }

    /** True when this user owns this taxpayer. */
    public static function owns(string $userId, string $subjectId): bool
    {
        return self::query()->where('user_id', $userId)->where('subject_id', $subjectId)->owned()->exists();
    }

    /**
     * Makes this user the owner of a taxpayer they have just registered — the one way an owner's
     * membership is written, whichever kind of taxpayer it is.
     */
    public static function grantOwnership(string $subjectId, string $userId): self
    {
        return self::create([
            'subject_id' => $subjectId,
            'user_id' => $userId,
            'role' => MembershipRole::OWNER,
            'permissions_json' => [],
            'valid_from' => now('UTC'),
            'revoked_at' => null,
        ]);
    }
}
