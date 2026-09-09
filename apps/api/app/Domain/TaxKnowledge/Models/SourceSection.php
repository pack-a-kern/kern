<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Database\Factories\SourceSectionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * `source_sections` — see `2026_09_08_301001_create_source_sections_table.php`'s docblock for the
 * two columns added beyond `BLUEPRINT.md`'s literal list (`effective_from`/`effective_until`).
 * Immutable once created for a given `document_version_id`, in the same sense
 * `source_document_versions` is: never edited in place; a re-parse
 * ({@see \App\Domain\TaxKnowledge\Services\LegislationSectionPersister}) replaces the whole set for
 * that version rather than mutating rows, because these are a *derived* index of the immutable
 * raw bytes, not the raw source itself (`docs/AGENTS.md` rule 17 governs the raw bytes, not their
 * re-computable interpretation).
 *
 * @property string $id
 * @property string $document_version_id
 * @property string|null $parent_id
 * @property SectionType $section_type
 * @property string $citation_label
 * @property int $ordinal
 * @property string $text
 * @property string $text_hash
 * @property int|null $page_start
 * @property int|null $page_end
 * @property \Illuminate\Support\Carbon|null $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_until
 */
#[UseFactory(SourceSectionFactory::class)]
final class SourceSection extends Model
{
    /** @use HasFactory<SourceSectionFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'document_version_id',
        'parent_id',
        'section_type',
        'citation_label',
        'ordinal',
        'text',
        'text_hash',
        'page_start',
        'page_end',
        'effective_from',
        'effective_until',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'section_type' => SectionType::class,
        'ordinal' => 'integer',
        'page_start' => 'integer',
        'page_end' => 'integer',
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
    ];
}
