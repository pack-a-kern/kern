<?php

declare(strict_types=1);

namespace Kern\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\ObjectStore\ObjectKey;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\TenantScope;
use RuntimeException;

/**
 * One file of a document (`document_parts`).
 *
 * A part is bytes: a key in the object store, the MIME its own content sniffed to, its size, and
 * its hash. What those bytes *mean* — the type, the extraction, the review state — belongs to the
 * {@see Document} and is deliberately absent here.
 *
 * ## Why this is not tenant-scoped itself
 *
 * There is no `tax_subject_id` column, and no {@see \Kern\Platform\Tenancy\ScopedToTenant}.
 * A part is reachable only through its document, which *is* scoped, so the tenant check happens
 * once at the only door — adding a second copy of the subject id here would create a second thing
 * that can disagree with the first about who owns this file.
 *
 * The encryption subject still has to be known, because the filename is encrypted with the
 * subject's DEK, so {@see encryptionSubjectId()} reads it from the loaded parent. That makes the
 * parent a hard requirement when assigning an encrypted attribute, which is why
 * {@see forDocument()} exists rather than callers filling `document_id` by hand.
 *
 * @property string $id
 * @property string $document_id
 * @property int $position
 * @property string $original_filename_ciphertext
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $object_path
 * @property int $object_encryption_version
 * @property DocumentStatus $status
 * @property RejectionReason|null $rejection_reason
 * @property-read Document $document
 */
final class DocumentPart extends Model implements EncryptsWithSubjectDek
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'position',
        'original_filename_ciphertext',
        'mime_type',
        'size_bytes',
        'sha256',
        'object_path',
        'object_encryption_version',
        'status',
        'rejection_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
        'original_filename_ciphertext' => Encrypted::class,
        'size_bytes' => 'integer',
        'object_encryption_version' => 'integer',
        'status' => DocumentStatus::class,
        'rejection_reason' => RejectionReason::class,
    ];

    /**
     * A new part of a document, with the parent already attached.
     *
     * The parent is set on the instance before anything else, because assigning the encrypted
     * filename needs a subject id and the only place a part can learn one is its document. A
     * `new DocumentPart([...])` with `document_id` in the array would not do — the relation would
     * not be loaded yet, and the cast would throw on a row that is otherwise perfectly valid.
     */
    public static function forDocument(Document $document, int $position): self
    {
        $part = new self();
        $part->document_id = $document->id;
        $part->position = $position;
        $part->setRelation('document', $document);

        return $part;
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Where this part's bytes live.
     *
     * Read from the stored path rather than rebuilt from ids: parts created before this table
     * existed keep the pre-parts key, and both layouts have to keep resolving. {@see
     * ObjectKey::fromStoredPath()} validates the shape, so this is not a free-form string reaching
     * the object store.
     */
    public function objectKey(): ObjectKey
    {
        return ObjectKey::fromStoredPath($this->object_path);
    }

    public function encryptionSubjectId(): string
    {
        // Unscoped on purpose: this runs inside the pipeline's own jobs, which have no bound
        // tenant context, and it is answering "whose key decrypts this filename" rather than
        // "may this user see it" — the part itself was only reachable through a scoped document.
        $document = $this->relationLoaded('document')
            ? $this->document
            : Document::withoutGlobalScope(TenantScope::class)->find($this->document_id);

        if (! $document instanceof Document) {
            throw new RuntimeException('DocumentPart must belong to a Document before an encrypted attribute is set.');
        }

        return $document->encryptionSubjectId();
    }
}
