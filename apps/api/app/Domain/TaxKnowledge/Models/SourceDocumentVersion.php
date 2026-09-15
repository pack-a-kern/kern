<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\SourceDocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `ext_source_document_versions` (`BLUEPRINT.md` §6.8/§9.1).
 *
 * **Identity is immutable, parsing metadata is not — these are different columns, deliberately.**
 * `source_document_id`/`content_sha256`/`raw_object_path`/`retrieved_at`/`size_bytes`/
 * `content_type` identify *which bytes this row is* and are never updated once created
 * (`docs/AGENTS.md` rule 17 — "overwrite a raw official source, ever" governs exactly these).
 * `parser_version`/`status`/`effective_from`/`effective_until` describe this milestone's
 * *interpretation* of those same, untouched bytes, and are updated in place by
 * {@see \App\Domain\TaxKnowledge\Services\LegislationSectionPersister} — re-parsing under an
 * improved parser, or a human later supplying effective dates, updates these columns without
 * creating a new version row and without touching a single byte of the raw content or its hash.
 *
 * @property string $id
 * @property string $source_document_id
 * @property \Illuminate\Support\Carbon $retrieved_at
 * @property \Illuminate\Support\Carbon|null $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_until
 * @property string $content_sha256
 * @property string $raw_object_path
 * @property int $size_bytes
 * @property string|null $content_type
 * @property string|null $parser_version
 * @property string|null $supersedes_version_id
 * @property SourceDocumentVersionStatus $status
 */
#[UseFactory(SourceDocumentVersionFactory::class)]
final class SourceDocumentVersion extends Model
{
    /** @use HasFactory<SourceDocumentVersionFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    protected $table = 'ext_source_document_versions';

    /** @var list<string> */
    protected $fillable = [
        'source_document_id',
        'retrieved_at',
        'effective_from',
        'effective_until',
        'content_sha256',
        'raw_object_path',
        'size_bytes',
        'content_type',
        'parser_version',
        'supersedes_version_id',
        'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'retrieved_at' => 'datetime',
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
        'size_bytes' => 'integer',
        'status' => SourceDocumentVersionStatus::class,
    ];
}
