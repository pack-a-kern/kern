<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * A registered WebAuthn credential (`user_passkeys` — see that migration's docblock for why
 * `credential_id`/`public_key` are intentionally plaintext, not `Casts\Encrypted`).
 *
 * No `HasFactory`/model factory: every test that needs one goes through the real registration
 * ceremony (`App\Domain\Identity\Actions\CompletePasskeyRegistration`) against a fixed test
 * authenticator key pair, exactly as a real passkey would be created — a factory would have to
 * fabricate a `credential_id`/`public_key` pair that no `CeremonyStepManager` step ever validated,
 * which is not a useful fixture for this model.
 *
 * @property string $id
 * @property string $user_id
 * @property string $credential_id
 * @property string $public_key
 * @property string $aaguid
 * @property string $attestation_type
 * @property list<string> $transports
 * @property int $sign_count
 * @property bool|null $backup_eligible
 * @property bool|null $backup_status
 * @property bool|null $uv_initialized
 * @property string|null $name
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
final class UserPasskey extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'aaguid',
        'attestation_type',
        'transports',
        'sign_count',
        'backup_eligible',
        'backup_status',
        'uv_initialized',
        'name',
        'last_used_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'transports' => 'array',
        'sign_count' => 'integer',
        'backup_eligible' => 'boolean',
        'backup_status' => 'boolean',
        'uv_initialized' => 'boolean',
        'last_used_at' => 'datetime',
    ];
}
