<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Tenancy\ScopedToTenant;

/**
 * `deposit_rate_periods` (`BLUEPRINT.md` §6.4). `annual_rate_decimal` is intentionally read/written
 * only through `Kern\TaxRomania\Wallet\ValueObjects\Decimal::fromString()` — no Eloquent cast is
 * applied, for the identical reason `Kern\TaxRomania\Fx\Models\FxRate` applies none to its own rate
 * column (see the migration's docblock: Laravel's built-in `decimal:N` cast round-trips through a
 * float internally).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $account_id
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property string $annual_rate_decimal
 */
final class DepositRatePeriod extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'account_id',
        'valid_from',
        'valid_to',
        'annual_rate_decimal',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    /** @return BelongsTo<DepositTerms, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(DepositTerms::class, 'account_id', 'account_id');
    }
}
