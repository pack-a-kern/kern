<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The on/off switch for one schedulable task — see this table's migration docblock for why this is
 * the *only* part of the schedule that lives in the database.
 *
 * A missing row means enabled. Callers should therefore read through
 * {@see \App\Domain\Operations\Services\ScheduledTaskRegistry} rather than querying this model
 * directly, so "no row yet" resolves to the code-declared default in exactly one place.
 *
 * @property string $key
 * @property bool $enabled
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ScheduledTask extends Model
{
    protected $table = 'scheduled_tasks';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'enabled', 'updated_by'];

    protected $casts = ['enabled' => 'boolean'];
}
