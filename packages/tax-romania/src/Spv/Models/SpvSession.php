<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Signed in and still open: the browser a sync reads in, and the one a second sign-in must not
     * replace. Newest first.
     *
     * `expires_at` is checked rather than trusted to the sweep: the worker drops a session at its own
     * TTL and tells nobody, so a row still saying AUTHENTICATED can describe a browser that is gone.
     * One definition for the list, the sync and the sign-in, because three copies of "live" had already
     * become two that could disagree.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('state', SpvSessionState::AUTHENTICATED)->open()->orderByDesc('created_at');
    }

    /**
     * Begun and never finished — a sign-in whose tab was closed before the code was entered. Its browser
     * is still open on the worker until the worker's own TTL ends it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [SpvSessionState::CREATED, SpvSessionState::AWAITING_USER_CHALLENGE])
            ->open();
    }

    /**
     * Not destroyed and not past its expiry: a browser that should still exist on the worker.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('destroyed_at')->where('expires_at', '>', now('UTC'));
    }
}
