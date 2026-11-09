<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Model;
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
 * @property string|null $last_result
 */
final class LawWatch extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_law_watches';

    /** @var list<string> */
    protected $fillable = [
        'key', 'declared_by', 'created_by', 'search_words', 'act_type', 'title_pattern', 'first_year',
        'every_hours', 'enabled', 'next_run_at', 'last_run_at', 'last_result',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'first_year' => 'integer',
        'every_hours' => 'integer',
        'enabled' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

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
