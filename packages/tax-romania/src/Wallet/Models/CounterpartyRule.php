<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kern\Platform\Crypto\Casts\Encrypted;
use Kern\Platform\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek;
use Kern\Platform\Tenancy\ScopedToTenant;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;

/**
 * `counterparty_rules` — what this taxpayer already decided about a counterparty, per direction.
 *
 * The name is stored here as well as on the transactions that share it, which this table originally
 * refused — "a second copy of the name here would be one more place holding it". That was true about
 * the cost and wrong about the consequence. `counterparty_blind_index` is `HMAC-SHA-256(key, name)`,
 * and rotating that key means recomputing it, which means hashing the name again. A row holding only
 * the hash is a row that can never be re-keyed, because a hash cannot be turned back into its input.
 * Every other blind index in this schema sits beside its own ciphertext for exactly this reason.
 *
 * The cost is genuinely one more encrypted field: it is sealed under the same taxpayer's DEK as the
 * copy on the transaction, so there is no additional key, principal or exposure surface. See this
 * table's `2026_09_27_100000_give_counterparty_rules_the_name_they_index` migration for the whole
 * argument, and `Kern\Platform\Console\Commands\KeyManagement\ReindexBlindIndexesCommand` for what
 * it makes possible.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $counterparty_ciphertext
 * @property string $counterparty_blind_index
 * @property MoneyDirection $direction
 * @property string|null $category_code
 * @property string|null $country_code
 * @property ProposalSource $source
 */
final class CounterpartyRule extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    protected $table = 'counterparty_rules';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'counterparty_ciphertext',
        'counterparty_blind_index',
        'direction',
        'category_code',
        'country_code',
        'source',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'counterparty_ciphertext' => Encrypted::class,
        'direction' => MoneyDirection::class,
        'source' => ProposalSource::class,
    ];

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'counterparty_rule_tags', 'rule_id', 'tag_id');
    }

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }
}
