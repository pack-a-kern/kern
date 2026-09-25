<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Models;

use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Enums\SubjectStatus;
use App\Domain\Subjects\Enums\SubjectType;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Database\Factories\TaxSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * `tax_subjects` (`BLUEPRINT.md` §5.2) — the tenant boundary. Every other business table this project
 * ever builds scopes its rows to one `tax_subject_id`; see
 * `App\Support\Tenancy\ScopedToTenant` for the reusable mechanism other modules use.
 *
 * `fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`/`fiscal_identifier_kind` are the
 * single canonical location for **any** subject type's fiscal identifier (ADR 0011,
 * `docs/adr/0011-canonical-fiscal-identifier.md`) — a PF's CNP, a PFA's business identifier, never
 * duplicated into a profile table. `fiscal_identifier_kind` disambiguates which
 * `App\Domain\Subjects\Enums\FiscalIdentifierKind` produced the blind index; it is plain (not
 * encrypted) because knowing a subject's identifier *is a CNP* is not itself sensitive.
 *
 * A `TaxSubject` encrypts its own `display_name`/`fiscal_identifier` under its own DEK —
 * `encryptionSubjectId()` returns its own primary key, the same self-referential pattern
 * `App\Domain\Identity\Models\User` uses. This is why creating one requires two writes (insert,
 * then update the encrypted columns) rather than one `create()` call — see
 * `App\Domain\Subjects\Actions\RegisterPfSubject`/`RegisterPfaSubject` and the `tax_subjects`
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
final class TaxSubject extends Model implements EncryptsWithSubjectDek
{
    /** @use HasFactory<\Database\Factories\TaxSubjectFactory> */
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

    /** @var array<string, string> */
    protected $casts = [
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
}
