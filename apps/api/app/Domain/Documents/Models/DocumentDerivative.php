<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\ObjectStore\DocumentObjectKind;

/**
 * `document_derivatives` (`BLUEPRINT.md` §6.6) — points at a binary/text artefact stored in the
 * encrypted object store; this row itself carries no PII (a table/column name, a SHA-256, an
 * `Kern\Platform\ObjectStore\ObjectKey` value built only from UUIDs and a closed enum — see that
 * class's own docblock), so it needs no encrypted column and no `EncryptsWithSubjectDek`
 * implementation.
 *
 * `kind` reuses `Kern\Platform\ObjectStore\DocumentObjectKind` directly rather than a second,
 * duplicate enum (`docs/CODE-QUALITY.md` §1) — only the derivative-shaped cases
 * (`THUMBNAIL|PDF_NORMALIZED|OCR|EXTRACTED_TEXT|STRUCTURED_JSON`) are ever written here by this
 * task's code; `ORIGINAL`/`PREVIEW` name a document's own primary content, never a derivative row.
 *
 * @property string $id
 * @property string $document_id
 * @property DocumentObjectKind $kind
 * @property string $object_path
 * @property string $sha256
 * @property int $encryption_version
 */
final class DocumentDerivative extends Model
{
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'kind',
        'object_path',
        'sha256',
        'encryption_version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => DocumentObjectKind::class,
        'encryption_version' => 'integer',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
