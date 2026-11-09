<?php

declare(strict_types=1);

namespace Kern\Investments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A company as its BVB page last showed it — see the migration that creates `ext_issuer_profiles`.
 *
 * @property string $id
 * @property string $issuer_key
 * @property list<array<string, mixed>> $meetings
 * @property list<array<string, mixed>> $shareholders
 * @property Carbon $read_at
 * @property string $evidence_id
 */
final class IssuerProfile extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_issuer_profiles';

    /** @var list<string> */
    protected $fillable = ['issuer_key', 'meetings', 'shareholders', 'read_at', 'evidence_id'];

    /** @var array<string, string> */
    protected $casts = [
        'meetings' => 'array',
        'shareholders' => 'array',
        'read_at' => 'datetime',
    ];
}
