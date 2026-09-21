<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use function app;

use App\Domain\Identity\Enums\UserStatus;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\Normalizer;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Database\Factories\UserFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;
use Spatie\Permission\Traits\HasRoles;

/**
 * Application identity (`BLUEPRINT.md` §5.1) — **not** a `TaxSubject`. A `User` authenticates; what
 * it may access is entirely determined by its `SubjectMembership` rows
 * (`App\Domain\Subjects\Models\SubjectMembership`), never by anything on this model itself.
 *
 * This model carries its own per-record DEK (`encrypted_dek`/`dek_kek_version`), independent of
 * any `TaxSubject`'s — see the `users` migration's docblock and
 * `docs/handoff/011-identity-tenancy.md` "Key decisions" for why. `encryptionSubjectId()` returns
 * the user's own id, exactly like `App\Domain\Subjects\Models\TaxSubject` does for itself.
 *
 * No authentication flow (passkeys/TOTP/login/password verification) lives here — that is a
 * separate, later task. This model exists only as the data this project's identity tables are
 * built from, plus the one lookup (`scopeWhereEmail`) any future login flow will need.
 *
 * **A `User` can never be created with `User::create(['email_ciphertext' => …])` in one call —
 * use {@see self::createWithEmail()}.** Exactly the same self-referential DEK constraint
 * `App\Domain\Subjects\Models\TaxSubject` documents applies here: `encryptionSubjectId()` returns
 * this row's own id, so encrypting `email_ciphertext` needs this row to already exist in the
 * database (`App\Domain\Subjects\Infrastructure\EloquentSubjectDekRepository::find()` has nothing
 * to query otherwise) — but a single-statement `create()` tries to encrypt the attribute *during*
 * construction, before any `INSERT` has run. Found empirically (not by inspection) while writing
 * this task's own tests — see `docs/handoff/011-identity-tenancy.md` "Key decisions".
 *
 * @property string $id
 * @property string|null $email_ciphertext
 * @property string $email_blind_index
 * @property string|null $password_hash
 * @property string|null $encrypted_dek
 * @property int|null $dek_kek_version
 * @property UserStatus $status
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 */
#[UseFactory(UserFactory::class)]
final class User extends Model implements AuthenticatableContract, EncryptsWithSubjectDek, AuthorizableContract
{
    /**
     * Platform roles and permissions (`config/access.php`).
     *
     * Distinct from `App\Domain\Subjects\Enums\MembershipRole`, which says what a user is
     * *to one tax subject*. A platform role says what they may do to the installation itself —
     * read the audit trail, change system settings — which has no tax subject to hang off.
     */
    use HasRoles;

    // Without this, `$user->can(...)` does not exist at all — every policy and every
    // permission check silently answers "no", which reads exactly like a correctly denied request.
    use Authorizable;
    use Authenticatable;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    // The bell's feed: notices stored in `notifications` and pushed on `users.{id}`.
    use Notifiable;

    /**
     * `Illuminate\Auth\Authenticatable::getAuthPassword()` reads this attribute instead of its
     * default `password` — this table's column is `password_hash` (`BLUEPRINT.md` §5.1). Overrides
     * the trait's `getAuthPasswordName()` method rather than its `$authPasswordName` property:
     * PHP does not allow a class to redeclare a trait property with a different default value
     * ("incompatible" fatal error, confirmed empirically), so the method is the only way to
     * change this without forking the trait. No "remember me" support is implemented
     * (`BLUEPRINT.md` §4.7 lists no such requirement, and a long-lived recaller cookie is a real
     * attack-surface increase for a tax-data product) — the `remember_token` column the trait's
     * other methods reference simply does not exist on this table, which is safe as long as
     * nothing ever calls `Auth::login($user, remember: true)`.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** @var list<string> */
    protected $fillable = [
        'email_ciphertext',
        'email_blind_index',
        'password_hash',
        'status',
        'email_verified_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'email_ciphertext' => Encrypted::class,
        'status' => UserStatus::class,
        'email_verified_at' => 'datetime',
    ];

    /**
     * Exact-match lookup by email without ever decrypting a row to find it (`BLUEPRINT.md` §4.4).
     * The blind index is computed under the single global key
     * (`App\Support\Crypto\BlindIndex\BlindIndexHasher`) — this is, by design, a cross-tenant
     * query (ADR 0002 "login-by-email is inherently cross-tenant"), not a tenant-scoped one.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->where(
            'email_blind_index',
            app(BlindIndexHasher::class)->compute(Normalizer::email($email)),
        );
    }

    public function encryptionSubjectId(): string
    {
        $value = $this->getKey();

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('User primary key must be a non-empty string.');
        }

        return $value;
    }

    /** @return HasMany<UserPasskey, $this> */
    public function passkeys(): HasMany
    {
        return $this->hasMany(UserPasskey::class);
    }

    /** @return HasMany<UserMfaMethod, $this> */
    public function mfaMethods(): HasMany
    {
        return $this->hasMany(UserMfaMethod::class);
    }

    /** @return HasMany<UserRecoveryCode, $this> */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserRecoveryCode::class);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * The only correct way to create a `User` with an email address — see this class's own
     * docblock for why a single `User::create(['email_ciphertext' => …])` call cannot work.
     * Inserts a bare row first (triggering no encryption at all), then assigns the encrypted
     * email in a second write, which is the write that creates this user's DEK on first use.
     */
    public static function createWithEmail(string $email, ?string $passwordHash = null): self
    {
        $user = new self();
        $user->password_hash = $passwordHash;
        $user->save();

        $user->email_ciphertext = $email;
        $user->email_blind_index = app(BlindIndexHasher::class)->compute(Normalizer::email($email));
        $user->save();

        return $user;
    }

    /**
     * The private channel this user's notices are pushed on, authorised in routes/channels.php.
     *
     * Named here rather than left to Laravel's default, which is the model's class name with dots —
     * a channel name that changes the day the class moves.
     */
    /**
     * This user's notices, newest first, in the model that keeps microseconds — see
     * `UserNotification`. Laravel's database channel writes new notices through this relation too.
     *
     * @return MorphMany<UserNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(UserNotification::class, 'notifiable')->latest();
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.' . $this->id;
    }
}
