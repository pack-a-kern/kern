<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Models;

use App\Domain\Subjects\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

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
}
