<?php

declare(strict_types=1);

namespace App\Domain\Fx\Models;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\FxSourceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `fx_sources` (`BLUEPRINT.md` §6.9). `official_source_id` is a plain foreign-key column, not an
 * Eloquent `belongsTo` — this module never imports `App\Domain\TaxKnowledge`'s models
 * (`docs/AGENTS.md` §6); see `App\Domain\Fx\Repositories\FxSourceRepository` for how a
 * cross-module id is obtained.
 *
 * @property string $id
 * @property string $official_source_id
 * @property string $code
 * @property int $priority
 */
#[UseFactory(FxSourceFactory::class)]
final class FxSource extends Model
{
    /** @use HasFactory<FxSourceFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'official_source_id',
        'code',
        'priority',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'priority' => 'integer',
    ];
}
