<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\SourceDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `source_documents` (`BLUEPRINT.md` §6.8) — see that migration's docblock for the identity rule
 * `(official_source_id, document_type, external_identifier)`.
 *
 * @property string $id
 * @property string $official_source_id
 * @property string $document_type
 * @property string|null $external_identifier
 * @property string $canonical_url
 * @property string|null $title
 * @property string|null $issuer
 * @property \Illuminate\Support\Carbon|null $publication_date
 */
#[UseFactory(SourceDocumentFactory::class)]
final class SourceDocument extends Model
{
    /** @use HasFactory<SourceDocumentFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'official_source_id',
        'document_type',
        'external_identifier',
        'canonical_url',
        'title',
        'issuer',
        'publication_date',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'publication_date' => 'date:Y-m-d',
    ];
}
