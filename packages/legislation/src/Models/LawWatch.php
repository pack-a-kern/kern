<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A search of the portal run again and again — see the migration that creates `ext_law_watches`.
 *
 * @property string $id
 * @property string|null $key
 * @property string|null $declared_by
 * @property string|null $created_by
 * @property string $search_words
 * @property string $act_type
 * @property string|null $title_pattern
 * @property int $first_year
 * @property int $every_hours
 * @property bool $enabled
 * @property Carbon $next_run_at
 * @property Carbon|null $last_run_at
 * @property array{years: list<int>, found: int, started: int, known: int, ignored: int, waiting: int, failed: int, retries: int, failure: string|null}|null $last_summary  what its last run did — `WatchRun::toArray()`
 * @property Carbon|null $run_requested_at  when a person asked it to run now, until that run ends
 * @property int|null $laws_count  the laws it read in, where counted
 */
final class LawWatch extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_law_watches';

    /** @var list<string> */
    protected $fillable = [
        'key', 'declared_by', 'created_by', 'search_words', 'act_type', 'title_pattern', 'first_year',
        'every_hours', 'enabled', 'next_run_at', 'last_run_at', 'last_summary', 'run_requested_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'first_year' => 'integer',
        'every_hours' => 'integer',
        'enabled' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_summary' => 'array',
        'run_requested_at' => 'datetime',
    ];

    /** @return HasMany<SourceDocument, $this> the laws it read in */
    public function laws(): HasMany
    {
        return $this->hasMany(SourceDocument::class, 'found_by_watch_id');
    }

    /**
     * Whether a run a person asked for is still to end: asked, and not longer ago than such a run may take
     * (`legislation.job_timeout_seconds`) — one the queue lost is not shown running for ever.
     */
    public function isRunning(): bool
    {
        return $this->run_requested_at !== null
            && $this->run_requested_at->greaterThan(now()->subSeconds(config()->integer('legislation.job_timeout_seconds')));
    }

    public function isDeclared(): bool
    {
        return $this->key !== null;
    }

    /** Whether an act's title is this watch's: every title, when it names no pattern. */
    public function keeps(string $title): bool
    {
        return $this->title_pattern === null || preg_match($this->title_pattern, $title) === 1;
    }
}
