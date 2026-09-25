<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;

/**
 * `spv_sessions` (`BLUEPRINT.md` §6.11) — one ephemeral `apps/spv-browser` session. No encrypted
 * fields: a session record is lifecycle metadata only, never SPV content.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $connection_id
 * @property string $browser_session_ref
 * @property SpvSessionState $state
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $challenge_requested_at
 * @property \Illuminate\Support\Carbon|null $destroyed_at
 */
final class SpvSession extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'spv_sessions';

    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'tax_subject_id',
        'connection_id',
        'browser_session_ref',
        'state',
        'created_at',
        'expires_at',
        'challenge_requested_at',
        'destroyed_at',
    ];

    protected $casts = [
        'state' => SpvSessionState::class,
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'challenge_requested_at' => 'datetime',
        'destroyed_at' => 'datetime',
    ];

    /** @return BelongsTo<SpvConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpvConnection::class, 'connection_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
