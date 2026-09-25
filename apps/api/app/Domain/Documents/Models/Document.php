<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\DocumentDisplayName;
use App\Domain\Documents\Enums\AiExtractionOutcome;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\RejectionReason;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use App\Support\Tenancy\ScopedToTenant;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * `documents` (`BLUEPRINT.md` §6.6) — one row per uploaded document, tenant-scoped to the owning
 * `TaxSubject` (`App\Support\Tenancy\ScopedToTenant`).
 *
 * A document is an *ordered list of files*, not a file: the object keys, MIME types, sizes and
 * per-file hashes live on {@see DocumentPart}. What stays here is what describes the document as a
 * whole — its type, where it came from, how far through the pipeline it is, and what was read out
 * of it. One PDF is still one document with one part; an invoice photographed four times is one
 * document with four.
 *
 * Unlike `TaxSubject`/`User`, `encryptionSubjectId()` returns the already-existing
 * `tax_subject_id` foreign key, not this row's own id — so, unlike those two models, a `Document`
 * needs no two-phase insert: `tax_subject_id` is always assigned first
 * (`FillsSubjectForeignKeyFirst`) and its owning `TaxSubject`'s DEK already exists by the time any
 * document upload can happen (a subject must be registered before it can own documents).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property DocumentType|null $type  null until something has read the document and said what it is
 * @property DocumentStatus $status
 * @property RejectionReason|null $rejection_reason
 * @property string|null $display_name_ciphertext
 * @property AiExtractionOutcome|null $ai_extraction_outcome
 * @property \Illuminate\Support\Carbon|null $ai_extraction_attempted_at
 * @property string|null $ai_reply_ciphertext  what the model last said, accepted or not
 * @property string|null $ai_model_provider
 * @property string|null $ai_model_name
 * @property string $sha256
 * @property int $part_count
 * @property DocumentSource $source
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DocumentPart> $parts
 * @property \Illuminate\Support\Carbon $received_at
 */
#[UseFactory(DocumentFactory::class)]
final class Document extends Model implements EncryptsWithSubjectDek
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory;
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'type',
        'status',
        'rejection_reason',
        'ai_extraction_outcome',
        'ai_extraction_attempted_at',
        'ai_reply_ciphertext',
        'ai_model_provider',
        'ai_model_name',
        'display_name_ciphertext',
        'sha256',
        'part_count',
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
        'ai_reply_ciphertext' => Encrypted::class,
        'display_name_ciphertext' => Encrypted::class,
        'part_count' => 'integer',
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

        // The first part's type, because that is the document a person recognises: an invoice
        // scanned as a PDF with a photographed annex is a PDF to them. The hash is the document's
        // composite one, so a multi-part document gets its own name rather than borrowing page 1's.
        return DocumentDisplayName::forContent($this->sha256, $this->firstPart()?->mime_type);
    }

    /** @return HasMany<DocumentPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(DocumentPart::class)->orderBy('position');
    }

    public function firstPart(): ?DocumentPart
    {
        return $this->relationLoaded('parts')
            ? $this->parts->first()
            : $this->parts()->first();
    }

    /**
     * This document's identity as content: the hash of its parts' hashes, in order.
     *
     * Order is included rather than sorted away, because two photographs of an invoice in the other
     * order are a different document — page 2 first reads differently and extracts differently.
     *
     * A one-part document hashes a single hash rather than being that hash, so that adding a second
     * page to a document always changes its identity. Were the one-part case passed through
     * unchanged, a document and its own first page would be indistinguishable.
     *
     * @param  list<string>  $partHashes  in position order
     */
    public static function contentHash(array $partHashes): string
    {
        return hash('sha256', implode("\n", $partHashes));
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
