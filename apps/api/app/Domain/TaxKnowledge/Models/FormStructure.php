<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Models;

use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * `ext_form_structures` — a declaration's structure file as ANAF publishes it, by its address and
 * the hash of what was read. The file itself is never kept.
 *
 * @property string $id
 * @property string $form_code
 * @property int $form_year
 * @property string $url
 * @property string $content_hash
 * @property list<array<string, mixed>>|null $unmatched  what it says that the law was searched for and not found
 * @property \Illuminate\Support\Carbon $read_at
 */
final class FormStructure extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'ext_form_structures';

    /** @var list<string> */
    protected $fillable = ['form_code', 'form_year', 'url', 'content_hash', 'unmatched', 'read_at'];

    /** @var array<string, string> */
    protected $casts = [
        'form_year' => 'integer',
        'unmatched' => 'array',
        'read_at' => 'datetime',
    ];
}
