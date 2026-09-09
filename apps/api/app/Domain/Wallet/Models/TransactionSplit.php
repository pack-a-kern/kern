<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `transaction_splits` (`BLUEPRINT.md` §6.3). No encrypted fields and no timestamp columns — see the
 * migration's docblock.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $transaction_id
 * @property int $amount_minor
 * @property string $category_code
 * @property string|null $linked_domain_type
 * @property string|null $linked_domain_id
 */
final class TransactionSplit extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'transaction_id',
        'amount_minor',
        'category_code',
        'linked_domain_type',
        'linked_domain_id',
    ];

    /** @return BelongsTo<FinancialTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }
}
