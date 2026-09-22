<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * `ext_section_histories` — one provision of a law, and one stretch of time its wording held.
 *
 * "Art. 170 alin. (2)" says one thing from 2021-01-01 until 2022-05-19 (`version_index` 2), another
 * from 2022-05-20. A stretch ends only when the words or numbers change — see
 * {@see \App\Domain\TaxKnowledge\Parsing\SectionText::fingerprint()} — not when the portal reformats
 * it. `first_section_id` is the section the wording first appeared in, whose text is shown.
 *
 * `coverage_complete` is false when a consolidation inside the stretch could not be read: the
 * wording may have changed and changed back there without anyone seeing it.
 *
 * @property string $id
 * @property string $source_document_id
 * @property string $citation_label
 * @property string $section_type
 * @property int $version_index
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property string $normative_hash
 * @property string $first_section_id
 * @property string $last_section_id
 * @property int $snapshot_count
 * @property \Illuminate\Support\Carbon|null $amended_on
 * @property bool $coverage_complete
 * @property bool $dated_by_note  its start is the day the portal's note gives, not a consolidation's
 */
final class SectionHistory extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_section_histories';

    /** @var list<string> */
    protected $fillable = [
        'source_document_id',
        'citation_label',
        'section_type',
        'version_index',
        'valid_from',
        'valid_until',
        'normative_hash',
        'first_section_id',
        'last_section_id',
        'snapshot_count',
        'amended_on',
        'coverage_complete',
        'dated_by_note',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'version_index' => 'integer',
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'snapshot_count' => 'integer',
        'amended_on' => 'date:Y-m-d',
        'coverage_complete' => 'boolean',
        'dated_by_note' => 'boolean',
    ];
}
