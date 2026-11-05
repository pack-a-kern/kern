<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of notification a person turned on or off for their devices, where that differs from the kind's
 * default (`notification_preferences` — see its migration). Only the device notifications are chosen
 * here: the bell inside the app keeps every notice, as the full history.
 *
 * @property string $user_id
 * @property string $type
 * @property bool $push_enabled
 */
final class NotificationPreference extends Model
{
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'type', 'push_enabled'];

    /** @var array<string, string> */
    protected $casts = ['push_enabled' => 'boolean'];
}
