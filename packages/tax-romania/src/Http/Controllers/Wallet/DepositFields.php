<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Validation\Rule;
use Kern\Platform\Money\CurrencyCode;
use Kern\TaxRomania\Banks\Queries\BankDirectory;
use Kern\TaxRomania\Rules\StreamSource;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositRatePeriodInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositTermsInput;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\InterestMethod;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Enums\RateType;

/**
 * What a term deposit entered on the screen must be, and what it becomes for the action.
 *
 * One place, because a deposit is entered two ways — one at a time on the account form, and many at
 * once from a CSV — and both must refuse exactly the same things. A rule kept twice is a rule that
 * one day differs, and the CSV is where nobody would notice: forty deposits in, the one whose
 * maturity was already past is the one settled the night it was imported.
 *
 * The rules are the *constraints* only. Whether a field is asked for at all is the caller's: the
 * account form asks for these only when the kind is a deposit, the CSV always.
 */
final readonly class DepositFields
{
    public function __construct(private ConfigRepository $config, private BankDirectory $banks)
    {
    }

    /**
     * The constraints on each deposit field, keyed by the field.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            // A deposit is named after its bank, chosen from the imported list rather than typed —
            // so its name is never sent, and its bank always is.
            'bank_id' => ['uuid', Rule::exists('ext_banks', 'id')->where('active', true)],
            // A deposit is denominated in one currency by definition.
            'currency_code' => CurrencyCode::rules(),
            // A deposit's own start date: the day interest begins accruing, and the left edge of
            // the span its rate periods must cover.
            'starts_at' => ['date'],
            'principal_minor' => ['integer', 'min:1'],
            // After today as well as after the start: maturity closes a deposit, so one entered
            // already matured would be settled the night it was typed in. Here, on what a person
            // enters, and not in `OpenFinancialAccount`: a deposit that matured in the past is a
            // perfectly valid fact for recording a previous year's interest by other means.
            'maturity_at' => ['date', 'after:starts_at', 'after:today'],
            // A decimal string, never a float: binary floating point cannot represent 5.40, and
            // this figure multiplies into a tax return. Percent per annum.
            'annual_rate' => ['string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'rate_type' => [Rule::enum(RateType::class)],
            'interest_method' => [Rule::enum(InterestMethod::class)],
            'payment_frequency' => [Rule::enum(PaymentFrequency::class)],
            // Optional, and the screen never sends it: the convention comes from the bank's
            // contract, not from a form, so the default lives in `config/wallet.php`.
            'day_count_convention' => ['sometimes', Rule::enum(DayCountConvention::class)],
            'funding_account_id' => ['sometimes', 'nullable', 'uuid', new StreamSource()],
        ];
    }

    /**
     * The fields every deposit must have — all of {@see rules()} but the two that are optional.
     *
     * @return list<string>
     */
    public static function required(): array
    {
        return array_values(array_diff(array_keys(self::rules()), ['day_count_convention', 'funding_account_id']));
    }

    /**
     * The deposit's name: its bank's.
     *
     * @param  array<string, mixed>  $validated
     */
    public function displayName(array $validated): string
    {
        return (string) $this->banks->find(self::str($validated, 'bank_id'))?->name;
    }

    /**
     * The deposit half of the form, as the action expects it.
     *
     * The screen sends a single annual rate, which becomes one period spanning the whole term so
     * the coverage check sees an unbroken range. A variable-rate deposit needs the periods
     * themselves; the action accepts them, the screen does not offer them yet.
     *
     * @param  array<string, mixed>  $validated
     */
    public function terms(array $validated): DepositTermsInput
    {
        // Narrowed through `self::str()`/`self::int()` rather than cast directly: `$validated`
        // is `array<string, mixed>`, and a bare cast on `mixed` is a static-analysis error and, on
        // an array, a runtime one. The validator has already guaranteed these are present and
        // well-shaped; this only makes that guarantee visible to the type system.
        $maturityAt = self::str($validated, 'maturity_at');
        $startsAt = self::str($validated, 'starts_at');

        return new DepositTermsInput(
            principalMinor: self::int($validated, 'principal_minor'),
            startsAt: $startsAt,
            // The source a deposit sits in may hold several currencies; the principal carries its own.
            currencyCode: strtoupper(self::str($validated, 'currency_code')),
            maturityAt: $maturityAt,
            rateType: RateType::from(self::str($validated, 'rate_type')),
            interestMethod: InterestMethod::from(self::str($validated, 'interest_method')),
            paymentFrequency: PaymentFrequency::from(self::str($validated, 'payment_frequency')),
            dayCountConvention: $this->dayCountConvention($validated),
            ratePeriods: [new DepositRatePeriodInput(
                validFrom: $startsAt,
                validTo: $maturityAt,
                annualRateDecimal: self::str($validated, 'annual_rate'),
            )],
            fundingAccountId: self::nullableString($validated, 'funding_account_id'),
            bankId: self::nullableString($validated, 'bank_id'),
        );
    }

    /** @param array<string, mixed> $validated */
    public static function nullableString(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $validated */
    public static function str(array $validated, string $key): string
    {
        $value = $validated[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $validated */
    public static function int(array $validated, string $key): int
    {
        $value = $validated[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The request's convention if it stated one, otherwise the configured default.
     *
     * Falls back to the enum's first case if that config value is mistyped, rather than throwing: a
     * typo in an environment variable must not make it impossible to open an account.
     *
     * @param  array<string, mixed>  $validated
     */
    private function dayCountConvention(array $validated): DayCountConvention
    {
        $requested = $validated['day_count_convention'] ?? null;

        if (is_string($requested) && $requested !== '') {
            return DayCountConvention::from($requested);
        }

        $configured = $this->config->get('wallet.deposit.default_day_count');

        return (is_string($configured) ? DayCountConvention::tryFrom($configured) : null)
            ?? DayCountConvention::cases()[0];
    }
}
