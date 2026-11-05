<?php

declare(strict_types=1);

namespace Kern\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One browser that receives a person's notices (`notification_devices` — see its migration).
 *
 * The push service's address and the two keys are encrypted: they are what reach that one browser,
 * and a copy of the table must not be a way to send to it. With Laravel's `encrypted` cast here, under
 * the application key; an application with keys of its own extends this model, casts the three its
 * way, and says so with `Notifications::useDeviceModel()`.
 *
 * @property string $id
 * @property string $notifiable_type
 * @property string|int $notifiable_id
 * @property string $endpoint_hash
 * @property string $endpoint
 * @property string $public_key
 * @property string $auth_token
 * @property string $locale
 * @property string|null $device_kind
 * @property string|null $device_browser
 * @property string|null $device_platform
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class Device extends Model
{
    use HasUuids;

    protected $table = 'notification_devices';

    /** @var list<string> */
    protected $fillable = [
        'endpoint_hash',
        'endpoint',
        'public_key',
        'auth_token',
        'locale',
        'device_kind',
        'device_browser',
        'device_platform',
        'last_used_at',
    ];

    /** @var list<string> */
    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    /** The one way to name a push address without keeping it in the clear. */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'public_key' => 'encrypted',
            'auth_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }
}
