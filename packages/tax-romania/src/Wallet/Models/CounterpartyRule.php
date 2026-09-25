<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;

/**
 * `counterparty_rules` — what this taxpayer already decided about a counterparty, per direction.
 *
 * No encrypted column of its own: the counterparty is found by its blind index, and a second copy of
 * the name here would be one more place holding it.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $counterparty_blind_index
 * @property MoneyDirection $direction
 * @property string|null $category_code
 * @property string|null $country_code
 * @property ProposalSource $source
 */
final class CounterpartyRule extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'counterparty_rules';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'counterparty_blind_index',
        'direction',
        'category_code',
        'country_code',
        'source',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'direction' => MoneyDirection::class,
        'source' => ProposalSource::class,
    ];

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'counterparty_rule_tags', 'rule_id', 'tag_id');
    }
}
