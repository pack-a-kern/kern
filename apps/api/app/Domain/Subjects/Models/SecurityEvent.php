<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * `security_events` (`BLUEPRINT.md` §6.1). Placed in `Subjects`, not `Identity`, even though the
 * blueprint's schema section lists it in the same group as `users`/`audit_events`: this
 * project's own `docs/ARCHITECTURE.md` module map does not assign `security_events` to either
 * module, and its only writer in this task is `Kern\Platform\Tenancy\TenantContext`
 * (denied cross-tenant access) — keeping it here avoids an otherwise-unneeded `Subjects → Identity`
 * Eloquent-model dependency for a table with no logic tying it to `Identity` specifically. A
 * later task adding login/session security events is free to write into this same table from
 * `Identity` via a plain `DB::table('security_events')` insert (or a DTO-based service call),
 * without needing to import this class, exactly as `docs/AGENTS.md` §6 prescribes for cross-module
 * traffic.
 *
 * `context_json` must never contain PII, a credential, or a token (`BLUEPRINT.md` §23) — only
 * structural identifiers (resource type/id, a reason code). This is enforced by convention and by
 * every caller in this task; there is no automatic redaction on this specific column the way
 * `Kern\Platform\Logging\SensitiveDataProcessor` redacts log lines.
 *
 * @property string $id
 * @property string $event_type
 * @property string|null $user_id
 * @property string|null $subject_id
 * @property array<string, mixed> $context_json
 * @property \Illuminate\Support\Carbon $occurred_at
 */
final class SecurityEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_type',
        'user_id',
        'subject_id',
        'context_json',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'context_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if ($event->getAttribute('id') === null) {
                $event->setAttribute('id', (string) Str::ulid());
            }

            if ($event->getAttribute('created_at') === null) {
                $event->setAttribute('created_at', now('UTC'));
            }
        });
    }
}
