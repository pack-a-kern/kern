<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxRules;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kern\Legislation\Enums\SourceRelation;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\Database\Factories\TaxRuleSourceFactory;

/**
 * `tax_rule_sources` — skeleton (`BLUEPRINT.md` §6.8). See that migration's docblock for the added
 * `id` primary key.
 *
 * @property string $id
 * @property string $rule_version_id
 * @property string $source_section_id
 * @property SourceRelation $relation
 */
#[UseFactory(TaxRuleSourceFactory::class)]
final class TaxRuleSource extends Model
{
    /** @use HasFactory<TaxRuleSourceFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'rule_version_id',
        'source_section_id',
        'relation',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'relation' => SourceRelation::class,
    ];
}
