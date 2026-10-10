<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Identity\Avatars\HasAvatar;
use Kern\TaxRomania\Database\Factories\TaxSubjectFactory;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Enums\SubjectStatus;
use Kern\TaxRomania\Subjects\Enums\SubjectType;
use RuntimeException;

/**
 * `tax_subjects` (`BLUEPRINT.md` §5.2) — the tenant boundary. Every other business table this project
 * ever builds scopes its rows to one `tax_subject_id`; see
 * `Kern\Platform\Tenancy\ScopedToTenant` for the reusable mechanism other modules use.
 *
 * `fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`/`fiscal_identifier_kind` are the
 * single canonical location for **any** subject type's fiscal identifier (ADR 0011,
 * `docs/adr/0011-canonical-fiscal-identifier.md`) — a PF's CNP, a PFA's business identifier, never
 * duplicated into a profile table. `fiscal_identifier_kind` disambiguates which
 * `Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind` produced the blind index; it is plain (not
 * encrypted) because knowing a subject's identifier *is a CNP* is not itself sensitive.
 *
 * A `TaxSubject` encrypts its own `display_name`/`fiscal_identifier` under its own DEK —
 * `encryptionSubjectId()` returns its own primary key, the same self-referential pattern
 * `Kern\Platform\Identity\Models\User` uses. This is why creating one requires two writes (insert,
 * then update the encrypted columns) rather than one `create()` call — see
 * `Kern\TaxRomania\Subjects\Actions\RegisterPfSubject`/`RegisterPfaSubject` and the `tax_subjects`
 * migration's docblock for exactly why.
 *
 * @property string $id
 * @property SubjectType $type
 * @property string $country_code
 * @property string|null $display_name_ciphertext
 * @property string|null $fiscal_identifier_ciphertext
 * @property string|null $fiscal_identifier_blind_index
 * @property FiscalIdentifierKind|null $fiscal_identifier_kind
 * @property string|null $encrypted_dek
 * @property int|null $dek_kek_version
 * @property SubjectStatus $status
 */
#[UseFactory(TaxSubjectFactory::class)]
final class TaxSubject extends Model implements EncryptsWithSubjectDek, HasAvatar
{
    /** @use HasFactory<\Kern\TaxRomania\Database\Factories\TaxSubjectFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'country_code',
        'display_name_ciphertext',
        'fiscal_identifier_ciphertext',
        'fiscal_identifier_blind_index',
        'fiscal_identifier_kind',
        'status',
        'display_currency',
    ];

    /*
     * The avatar columns are deliberately absent from `$fillable`, exactly as they are on `User`: a
     * path is written by `SetAvatar` after it has produced the file, and nothing that mass-assigns a
     * taxpayer should be able to point one at a path no upload ever produced.
     */

    /** @var array<string, string> */
    protected $casts = [
        'avatar_updated_at' => 'datetime',
        'type' => SubjectType::class,
        'status' => SubjectStatus::class,
        'display_name_ciphertext' => Encrypted::class,
        'fiscal_identifier_ciphertext' => Encrypted::class,
        'fiscal_identifier_kind' => FiscalIdentifierKind::class,
    ];

    public function encryptionSubjectId(): string
    {
        $value = $this->getKey();

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('TaxSubject primary key must be a non-empty string.');
        }

        return $value;
    }

    public function avatarPath(): ?string
    {
        $value = $this->getAttribute('avatar_path');

        return is_string($value) ? $value : null;
    }

    public function saveAvatar(?string $path, ?Carbon $at): void
    {
        $this->setAttribute('avatar_path', $path);
        $this->setAttribute('avatar_updated_at', $at);
        $this->save();
    }

    /** The cache key an image URL carries, as the wire shape wants it, or null when there is none. */
    public function avatarUpdatedAt(): ?string
    {
        $value = $this->getAttribute('avatar_updated_at');

        return $value instanceof Carbon ? $value->toIso8601String() : null;
    }

    public function avatarOwnerId(): string
    {
        return $this->encryptionSubjectId();
    }

    /* What an auditor reads. The table's name, so the row says which kind of thing changed. */
    public function avatarOwnerType(): string
    {
        return 'tax_subject';
    }
}
