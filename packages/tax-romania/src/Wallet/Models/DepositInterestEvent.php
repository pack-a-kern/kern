<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey;
use Kern\Platform\Tenancy\ScopedToTenant;

/**
 * `deposit_interest_events` (`BLUEPRINT.md` §6.4). See the migration's docblock for the FX
 * provenance columns and the fiscal-neutrality decision behind `withholding_minor`.
 *
 * `fx_rate_decimal` is read through `Kern\TaxRomania\Fx\ValueObjects\DecimalRate::fromString()` by
 * callers that need it as a decimal (not this model itself, which applies no cast to it — same
 * "string column, BCMath reader" pattern as `DepositRatePeriod::$annual_rate_decimal`).
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $account_id
 * @property \Illuminate\Support\Carbon $accrual_from
 * @property \Illuminate\Support\Carbon $accrual_to
 * @property string $currency_code
 * @property int $gross_interest_minor
 * @property int|null $withholding_minor
 * @property int $net_interest_minor
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property string|null $transaction_id
 * @property string $calculation_version
 * @property string|null $fx_rate_decimal
 * @property \Illuminate\Support\Carbon|null $fx_rate_date
 * @property string|null $fx_source_code
 * @property string|null $fx_method
 * @property string|null $fx_content_sha256
 * @property string|null $fx_source_document_version_id
 * @property int|null $ron_equivalent_minor
 */
final class DepositInterestEvent extends Model
{
    use HasUuidPrimaryKey;
    use ScopedToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'account_id',
        'accrual_from',
        'accrual_to',
        'currency_code',
        'gross_interest_minor',
        'withholding_minor',
        'net_interest_minor',
        'paid_at',
        'transaction_id',
        'calculation_version',
        'fx_rate_decimal',
        'fx_rate_date',
        'fx_source_code',
        'fx_method',
        'fx_content_sha256',
        'fx_source_document_version_id',
        'ron_equivalent_minor',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'accrual_from' => 'date',
        'accrual_to' => 'date',
        'paid_at' => 'date',
        'fx_rate_date' => 'date',
    ];

    /** @return BelongsTo<DepositTerms, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(DepositTerms::class, 'account_id', 'account_id');
    }
}
