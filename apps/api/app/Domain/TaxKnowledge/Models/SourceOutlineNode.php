<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Domain\TaxKnowledge\Enums\OutlineLevel;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;

/**
 * `ext_source_outline` — one heading of a version's table of contents.
 *
 * "Titlul IV" "Impozitul pe venit", and under it "Capitolul IV" "Venituri din cedarea folosinței
 * bunurilor". The articles under a heading point at it through `ext_source_sections.outline_id`.
 * `path` — "Titlul IV Capitolul IV" — is the same in every consolidation, so a chapter can be
 * followed from one to the next.
 *
 * @property string $id
 * @property string $document_version_id
 * @property string|null $parent_id
 * @property OutlineLevel $level
 * @property string $label
 * @property string $name
 * @property string $path
 * @property int $ordinal
 * @property int $position  its place in the whole table of contents, in reading order
 */
final class SourceOutlineNode extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_source_outline';

    /** @var list<string> */
    protected $fillable = [
        'document_version_id',
        'parent_id',
        'level',
        'label',
        'name',
        'path',
        'ordinal',
        'position',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'level' => OutlineLevel::class,
        'ordinal' => 'integer',
        'position' => 'integer',
    ];
}
