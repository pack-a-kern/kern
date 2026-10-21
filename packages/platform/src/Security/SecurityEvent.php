<?php

declare(strict_types=1);

namespace Kern\Platform\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * `security_events` (`BLUEPRINT.md` §6.1) — a failed sign-in, a refused second factor, a refused
 * tenant: occurrences to be counted and alerted on, not the tamper-evident audit chain.
 *
 * Written only through {@see RecordSecurityEvent}, which is where the rule about what a row may
 * carry is kept. It lived in tax-romania once, and the platform wrote the same table by hand three
 * times beside it.
 *
 * @property string $id
 * @property SecurityEventType $event_type
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
        'event_type' => SecurityEventType::class,
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
