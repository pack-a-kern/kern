<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * `deposits` (`BLUEPRINT.md` §6.4). Same encrypted-field/tenancy-trait interaction as
 * `FinancialAccount` — `tax_subject_id` must be passed explicitly at construction.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string|null $funding_account_id
 * @property string|null $institution_name
 * @property int $principal_minor
 * @property string $currency_code
 * @property \Illuminate\Support\Carbon $opened_at
 * @property \Illuminate\Support\Carbon $maturity_at
 * @property RateType $rate_type
 * @property InterestMethod $interest_method
 * @property PaymentFrequency $payment_frequency
 * @property DayCountConvention $day_count_convention
 * @property bool $auto_renewal
 * @property string|null $metadata_ciphertext
 */
final class Deposit extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'funding_account_id',
        'institution_name',
        'principal_minor',
        'currency_code',
        'opened_at',
        'maturity_at',
        'rate_type',
        'interest_method',
        'payment_frequency',
        'day_count_convention',
        'auto_renewal',
        'metadata_ciphertext',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'opened_at' => 'date',
        'maturity_at' => 'date',
        'rate_type' => RateType::class,
        'interest_method' => InterestMethod::class,
        'payment_frequency' => PaymentFrequency::class,
        'day_count_convention' => DayCountConvention::class,
        'auto_renewal' => 'boolean',
        'metadata_ciphertext' => Encrypted::class,
    ];

    /** @return HasMany<DepositRatePeriod, $this> */
    public function ratePeriods(): HasMany
    {
        return $this->hasMany(DepositRatePeriod::class, 'deposit_id')->orderBy('valid_from');
    }

    /** @return HasMany<DepositInterestEvent, $this> */
    public function interestEvents(): HasMany
    {
        return $this->hasMany(DepositInterestEvent::class, 'deposit_id')->orderBy('accrual_from');
    }

    public function encryptionSubjectId(): string
    {
        $value = $this->getAttribute('tax_subject_id');

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('tax_subject_id must be a non-empty string.');
        }

        return $value;
    }
}
