<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kern\Legislation\Database\Factories\SourceDocumentFactory;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * `ext_source_documents` (`BLUEPRINT.md` §6.8) — see that migration's docblock for the identity rule
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
 * @property string|null $act_type_label  the act's type as the portal writes it — `HOTĂRÂRE`
 * @property string|null $act_number
 * @property \Illuminate\Support\Carbon|null $act_date
 * @property string|null $official_journal  where it was published — `Monitorul Oficial`
 * @property string|null $official_journal_number
 * @property \Illuminate\Support\Carbon|null $in_force_from
 * @property string|null $portal_document_id  the act as first published, on legislatie.just.ro
 * @property string|null $consolidated_document_id  the page that lists its consolidations
 * @property list<array{date: string, ref: string, url: string, failed?: bool}>|null $consolidations
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
#[UseFactory(SourceDocumentFactory::class)]
final class SourceDocument extends Model
{
    /** @use HasFactory<SourceDocumentFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    protected $table = 'ext_source_documents';

    /** @var list<string> */
    protected $fillable = [
        'official_source_id',
        'document_type',
        'external_identifier',
        'canonical_url',
        'title',
        'issuer',
        'publication_date',
        'act_type_label',
        'act_number',
        'act_date',
        'official_journal',
        'official_journal_number',
        'in_force_from',
        'portal_document_id',
        'consolidated_document_id',
        'consolidations',
        'last_synced_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'publication_date' => 'date:Y-m-d',
        'act_date' => 'date:Y-m-d',
        'in_force_from' => 'date:Y-m-d',
        'consolidations' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
