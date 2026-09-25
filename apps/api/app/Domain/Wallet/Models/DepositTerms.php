<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Support\Tenancy\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `deposit_terms` — what makes one `FinancialAccount` a term deposit rather than a current account.
 *
 * A 1:1 extension keyed by the account's own id, replacing the old separate `deposits` table that
 * duplicated the account's institution, currency and opening date and could disagree with it. The
 * account owns everything an account has; this row owns only what a *deposit* additionally is: a
 * principal locked for a term, at a rate, compounded or not, counted a particular way.
 *
 * `starts_at` and `currency_code` moved back here once the account stopped being a bank
 * relationship: a source may hold several products and cannot carry one start date or one currency
 * for all of them, and `starts_at` is not metadata — it is the date interest begins accruing, the
 * left edge of the span the rate periods must cover exactly.
 *
 * The primary key is `account_id`, so a second set of terms for the same account is not a state
 * this table can represent.
 *
 * @property string $account_id
 * @property string $tax_subject_id
 * @property string|null $funding_account_id
 * @property string|null $bank_id
 * @property int $principal_minor
 * @property \Illuminate\Support\Carbon $starts_at
 * @property string $currency_code
 * @property \Illuminate\Support\Carbon $maturity_at
 * @property RateType $rate_type
 * @property InterestMethod $interest_method
 * @property PaymentFrequency $payment_frequency
 * @property DayCountConvention $day_count_convention
 * @property string|null $funding_transaction_id the money that left the funding account, when there is one
 * @property string|null $settlement_transaction_id the money that came back to it at maturity
 * @property \Illuminate\Support\Carbon|null $closed_at when the deposit matured and was settled
 */
final class DepositTerms extends Model
{
    use ScopedToTenant;

    /** The account's id, assigned by the caller — never generated here. */
    protected $primaryKey = 'account_id';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'tax_subject_id',
        'funding_account_id',
        'bank_id',
        'principal_minor',
        'starts_at',
        'currency_code',
        'maturity_at',
        'rate_type',
        'interest_method',
        'payment_frequency',
        'day_count_convention',
        'funding_transaction_id',
        'settlement_transaction_id',
        'closed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal_minor' => 'integer',
        'starts_at' => 'date',
        'maturity_at' => 'date',
        'rate_type' => RateType::class,
        'interest_method' => InterestMethod::class,
        'payment_frequency' => PaymentFrequency::class,
        'day_count_convention' => DayCountConvention::class,
        'closed_at' => 'date',
    ];

    /** @return BelongsTo<FinancialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    /** @return HasMany<DepositRatePeriod, $this> */
    public function ratePeriods(): HasMany
    {
        return $this->hasMany(DepositRatePeriod::class, 'account_id', 'account_id')->orderBy('valid_from');
    }

    /** @return HasMany<DepositInterestEvent, $this> */
    public function interestEvents(): HasMany
    {
        return $this->hasMany(DepositInterestEvent::class, 'account_id', 'account_id')->orderBy('accrual_from');
    }
}
