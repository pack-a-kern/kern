<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A device/session-listing row (`user_sessions` — see that migration's docblock for why its `id`
 * *is* the real session-store id, and why `ip_address`/`user_agent` are plaintext). No
 * `HasUuidPrimaryKey`: the primary key is never generated here, it is always assigned explicitly
 * from `Illuminate\Contracts\Session\Session::getId()` by `Kern\Platform\Identity\Support\
 * SessionRegistrar`.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $last_used_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class UserSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'ip_address',
        'user_agent',
        'last_used_at',
        'created_at',
        'revoked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isRevoked(): bool
    {
        return $this->getAttribute('revoked_at') !== null;
    }
}
