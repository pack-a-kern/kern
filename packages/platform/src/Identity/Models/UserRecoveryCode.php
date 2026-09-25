<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A single-use MFA recovery code (`user_recovery_codes` — see that migration's docblock for why
 * `code_hash` is a one-way `Hash::make()` value, not `Casts\Encrypted`). `$timestamps = false`
 * because there is no `updated_at` column: a row is written once at generation and mutated exactly
 * once (`used_at` set) for its entire life, never otherwise touched.
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class UserRecoveryCode extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $code): void {
            if ($code->getAttribute('created_at') === null) {
                $code->setAttribute('created_at', now('UTC'));
            }
        });
    }
}
