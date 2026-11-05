<?php

declare(strict_types=1);

namespace Kern\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A kind of notice a person turned off — or back on — for their devices (`notification_preferences`).
 * Only the devices are chosen here: the bell keeps every notice, as the full history.
 *
 * @property string $notifiable_type
 * @property string|int $notifiable_id
 * @property string $type
 * @property bool $push_enabled
 */
final class Preference extends Model
{
    public $incrementing = false;

    protected $table = 'notification_preferences';

    /** @var list<string> */
    protected $fillable = ['notifiable_type', 'notifiable_id', 'type', 'push_enabled'];

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['push_enabled' => 'boolean'];
    }
}
