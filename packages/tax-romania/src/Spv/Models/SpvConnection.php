<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Spv\Enums\SpvAuthMethod;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Enums\SpvCredentialPolicy;

/**
 * `spv_connections` (`BLUEPRINT.md` §6.11). See this table's migration docblock for why there is no
 * `password_ciphertext` column and why `credential_policy` exists.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property SpvAuthMethod $auth_method
 * @property SpvCredentialPolicy $credential_policy
 * @property string|null $username_ciphertext
 * @property string|null $username_blind_index
 * @property SpvConnectionStatus $status
 * @property \Illuminate\Support\Carbon|null $last_success_at
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 */
final class SpvConnection extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'spv_connections';

    protected $fillable = [
        'tax_subject_id',
        'auth_method',
        'credential_policy',
        'username_ciphertext',
        'username_blind_index',
        'status',
        'last_success_at',
        'last_error_code',
        'last_error_message',
    ];

    protected $casts = [
        'auth_method' => SpvAuthMethod::class,
        'credential_policy' => SpvCredentialPolicy::class,
        'status' => SpvConnectionStatus::class,
        'username_ciphertext' => Encrypted::class,
        'last_success_at' => 'datetime',
    ];

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }

    /** @return HasMany<SpvSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(SpvSession::class, 'connection_id');
    }

    /** @return HasMany<SpvSyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SpvSyncRun::class, 'connection_id');
    }
}
