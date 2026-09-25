<?php

declare(strict_types=1);

namespace App\Domain\Spv\Models;

use App\Domain\Spv\Enums\SpvSyncRunStatus;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Tenancy\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `spv_sync_runs` (`BLUEPRINT.md` §6.11). `summary_json` carries counts only, never message content
 * — see this table's migration docblock.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $connection_id
 * @property SpvSyncRunStatus $status
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property array<string, mixed>|null $summary_json
 */
final class SpvSyncRun extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'spv_sync_runs';

    public $timestamps = false;

    protected $fillable = [
        'tax_subject_id',
        'connection_id',
        'status',
        'started_at',
        'completed_at',
        'summary_json',
    ];

    protected $casts = [
        'status' => SpvSyncRunStatus::class,
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'summary_json' => 'array',
    ];

    /** @return BelongsTo<SpvConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SpvConnection::class, 'connection_id');
    }
}
