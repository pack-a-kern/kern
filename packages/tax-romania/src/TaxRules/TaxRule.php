<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxRules;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\TaxRomania\Database\Factories\TaxRuleFactory;

/**
 * `tax_rules` — skeleton (`BLUEPRINT.md` §6.8). See that migration's docblock for why `subject_type`
 * is a plain string, not a shared enum with `Kern\TaxRomania\Subjects`.
 *
 * @property string $id
 * @property string $code
 * @property string $jurisdiction
 * @property string $tax_code
 * @property string|null $subject_type
 * @property string|null $income_category
 */
#[UseFactory(TaxRuleFactory::class)]
final class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory;
    use HasUuidPrimaryKey;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'jurisdiction',
        'tax_code',
        'subject_type',
        'income_category',
    ];
}
