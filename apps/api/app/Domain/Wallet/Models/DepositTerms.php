<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
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
 * `opened_at`, `currency_code` and `institution_name` are deliberately absent — read them from the
 * account. That is the whole point of the change.
 *
 * The primary key is `account_id`, so a second set of terms for the same account is not a state
 * this table can represent.
 *
 * @property string $account_id
 * @property string $tax_subject_id
 * @property string|null $funding_account_id
 * @property int $principal_minor
 * @property \Illuminate\Support\Carbon $maturity_at
 * @property RateType $rate_type
 * @property InterestMethod $interest_method
 * @property PaymentFrequency $payment_frequency
 * @property DayCountConvention $day_count_convention
 * @property bool $auto_renewal
 */
final class DepositTerms extends Model
{
    use ScopedToTaxSubject;

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
        'principal_minor',
        'maturity_at',
        'rate_type',
        'interest_method',
        'payment_frequency',
        'day_count_convention',
        'auto_renewal',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal_minor' => 'integer',
        'maturity_at' => 'date',
        'rate_type' => RateType::class,
        'interest_method' => InterestMethod::class,
        'payment_frequency' => PaymentFrequency::class,
        'day_count_convention' => DayCountConvention::class,
        'auto_renewal' => 'boolean',
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
