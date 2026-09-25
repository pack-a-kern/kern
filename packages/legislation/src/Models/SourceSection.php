<?php

declare(strict_types=1);

namespace Kern\Legislation\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Legislation\Database\Factories\SourceSectionFactory;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\SectionText;
use Kern\Legislation\Services\NoteReader;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * `ext_source_sections` — see `2026_09_08_301001_create_source_sections_table.php`'s docblock for the
 * two columns added beyond `BLUEPRINT.md`'s literal list (`effective_from`/`effective_until`).
 * Immutable once created for a given `document_version_id`, in the same sense
 * `ext_source_document_versions` is: never edited in place; a re-parse
 * ({@see \Kern\Legislation\Services\LegislationSectionPersister}) replaces the whole set for
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
 * @property list<list<list<string>>>|null $tables  each table's rows of cells, in the order their markers appear in `text`
 * @property \Illuminate\Support\Carbon|null $amended_on  the date of the section's latest amendment note
 * @property string|null $normative_hash  see {@see SectionText::fingerprint()}
 * @property string|null $outline_id  for an article or annex: the heading it stands under
 * @property list<ReadNote>|null $notes  the portal's notes on it, as {@see NoteReader} read them
 *
 * @phpstan-import-type ReadNote from NoteReader
 */
#[UseFactory(SourceSectionFactory::class)]
final class SourceSection extends Model
{
    /** @use HasFactory<SourceSectionFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    protected $table = 'ext_source_sections';

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
        'tables',
        'amended_on',
        'normative_hash',
        'outline_id',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'section_type' => SectionType::class,
        'ordinal' => 'integer',
        'page_start' => 'integer',
        'page_end' => 'integer',
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
        'tables' => 'array',
        'amended_on' => 'date:Y-m-d',
        'notes' => 'array',
    ];

    /** @return BelongsTo<SourceDocumentVersion, $this> the consolidation it is part of */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SourceDocumentVersion::class, 'document_version_id');
    }

    /** The text with its tables written out row by row — what is searched and quoted. */
    public function fullText(): string
    {
        return SectionText::withTables($this->text, $this->tables ?? []);
    }
}
