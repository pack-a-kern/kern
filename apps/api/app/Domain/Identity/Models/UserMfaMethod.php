<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\MfaMethodType;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A user's enrolled MFA method (`user_mfa_methods` — see that migration's docblock). `secret_
 * ciphertext` is encrypted under **the owning `User`'s own DEK**, not a DEK of this row's own: the
 * TOTP secret belongs to the user, not to this join-row, so it shares `users.email_ciphertext`'s
 * encryption key exactly the way `App\Domain\Subjects\Models\PfProfile`'s fields share their
 * owning `TaxSubject`'s DEK. `encryptionSubjectId()` therefore returns `user_id`, not `$this->id`
 * — the opposite of `App\Domain\Identity\Models\User`'s own self-referential
 * `encryptionSubjectId()`.
 *
 * `FillsSubjectForeignKeyFirst` is overridden to read `user_id` (its default is `tax_subject_id`)
 * — see that trait's docblock for the attribute-assignment-order trap this closes.
 *
 * @property string $id
 * @property string $user_id
 * @property MfaMethodType $type
 * @property string $secret_ciphertext
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
final class UserMfaMethod extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'secret_ciphertext',
        'confirmed_at',
        'last_used_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => MfaMethodType::class,
        'secret_ciphertext' => Encrypted::class,
        'confirmed_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function isConfirmed(): bool
    {
        return $this->getAttribute('confirmed_at') !== null;
    }

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('user_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('UserMfaMethod.user_id must be a non-empty string.');
        }

        return $value;
    }

    protected function subjectForeignKeyName(): string
    {
        return 'user_id';
    }
}
