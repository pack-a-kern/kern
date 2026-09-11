<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\DocumentDisplayName;
use App\Domain\Documents\Enums\AiExtractionOutcome;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * `documents` (`BLUEPRINT.md` §6.6) — one row per uploaded document, tenant-scoped to the owning
 * `TaxSubject` (`App\Domain\Subjects\Tenancy\ScopedToTaxSubject`). The row never holds the
 * document's own bytes — only its verified metadata and its `App\Support\ObjectStore\ObjectKey`
 * value (`object_path`); the encrypted content lives in the object store.
 *
 * Unlike `TaxSubject`/`User`, `encryptionSubjectId()` returns the already-existing
 * `tax_subject_id` foreign key, not this row's own id — so, unlike those two models, a `Document`
 * needs no two-phase insert: `tax_subject_id` is always assigned first
 * (`FillsSubjectForeignKeyFirst`) and its owning `TaxSubject`'s DEK already exists by the time any
 * document upload can happen (a subject must be registered before it can own documents).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property RejectionReason|null $rejection_reason
 * @property string $original_filename_ciphertext
 * @property string|null $display_name_ciphertext
 * @property AiExtractionOutcome|null $ai_extraction_outcome
 * @property \Illuminate\Support\Carbon|null $ai_extraction_attempted_at
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $object_path
 * @property int $object_encryption_version
 * @property DocumentSource $source
 * @property \Illuminate\Support\Carbon $received_at
 */
#[UseFactory(DocumentFactory::class)]
final class Document extends Model implements EncryptsWithSubjectDek
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory;
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'type',
        'status',
        'rejection_reason',
        'ai_extraction_outcome',
        'ai_extraction_attempted_at',
        'original_filename_ciphertext',
        'display_name_ciphertext',
        'mime_type',
        'size_bytes',
        'sha256',
        'object_path',
        'object_encryption_version',
        'source',
        'received_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => DocumentType::class,
        'status' => DocumentStatus::class,
        'rejection_reason' => RejectionReason::class,
        'ai_extraction_outcome' => AiExtractionOutcome::class,
        'ai_extraction_attempted_at' => 'datetime',
        'original_filename_ciphertext' => Encrypted::class,
        'display_name_ciphertext' => Encrypted::class,
        'size_bytes' => 'integer',
        'object_encryption_version' => 'integer',
        'source' => DocumentSource::class,
        'received_at' => 'datetime',
    ];

    /**
     * What to call this document on screen.
     *
     * The name a person gave it if they gave it one, and otherwise a name derived from the content
     * hash — never the name the file arrived with, which is `IMG_0042.jpg` as often as not and can
     * carry anything whoever named it chose to put in it.
     *
     * The one place this decision is made, so a list, a review screen and a suggestion cannot
     * disagree about what a document is called.
     */
    public function presentedFilename(): string
    {
        $chosen = $this->display_name_ciphertext;

        if (is_string($chosen) && $chosen !== '') {
            return $chosen;
        }

        return DocumentDisplayName::forContent($this->sha256, $this->mime_type);
    }

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Document must have a tax_subject_id before an encrypted attribute is set.');
        }

        return $value;
    }
}
