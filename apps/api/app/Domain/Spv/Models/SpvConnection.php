<?php

declare(strict_types=1);

namespace App\Domain\Spv\Models;

use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Enums\SpvConnectionStatus;
use App\Domain\Spv\Enums\SpvCredentialPolicy;
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 */
final class SpvConnection extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

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
