<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\TaxRomania\Banks\Queries\BankDirectory;
use Kern\TaxRomania\Wallet\Actions\UpdateFinancialAccount;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositRatePeriodInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\DepositTermsInput;
use Kern\TaxRomania\Wallet\DataTransferObjects\UpdateFinancialAccountInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\InterestMethod;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Enums\RateType;
use Kern\TaxRomania\Wallet\Exceptions\DepositClosedException;
use Kern\TaxRomania\Wallet\Exceptions\DepositTermsLockedException;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * `PATCH /api/v1/subjects/{subject}/wallet/accounts/{account}` — edit an account.
 *
 * `sometimes` on every rule, so an absent key means "unchanged" and a present one is validated. The
 * kind and the currency are not accepted at all: both are part of the account's identity and every
 * figure recorded against it depends on them (`UpdateFinancialAccountInput` explains why).
 *
 * A term deposit's terms are edited through the same request, and editing them re-computes the
 * unpaid part of its interest schedule. Periods already settled are never touched.
 */
final class UpdateAccountController
{
    public function __invoke(
        Request $request,
        string $subject,
        string $account,
        UpdateFinancialAccount $update,
        BankDirectory $banks,
    ): JsonResponse {
        // Tenant-scoped: another subject's account is not found rather than edited.
        $model = FinancialAccount::query()->findOrFail($account);
        $isDeposit = $model->kind === AccountKind::TERM_DEPOSIT;

        // The constraints a deposit's fields carry everywhere; whether a field may be sent at all
        // depends on the account, and is decided per field below.
        $deposit = DepositFields::rules();

        $validated = $request->validate([
            // Rejected outright rather than ignored: `kind` decides whether transactions may be
            // recorded against this source at all, so changing it would reinterpret what is already
            // stored. Silently dropping the field would let a caller believe the change took
            // effect. Getting it wrong means deleting the source and opening the right one.
            'kind' => ['prohibited'],

            // A deposit is named after its bank: changing the bank renames it, and a typed name is refused.
            'display_name' => [$isDeposit ? 'prohibited' : 'sometimes', 'string', 'max:120'],
            'bank_id' => [
                $isDeposit ? 'sometimes' : 'prohibited',
                'uuid',
                // An active bank, or the one this deposit is already at: a bank that has since left the
                // list must not make the rest of the deposit uneditable.
                Rule::exists('ext_banks', 'id')->where(static function ($query) use ($model): void {
                    $query->where('active', true)->orWhere('id', $model->depositTerms?->bank_id);
                }),
            ],
            'holding_amount_minor' => [
                $model->kind === AccountKind::CASH ? 'sometimes' : 'prohibited',
                'integer',
            ],
            // A deposit's own dates and currency travel with its terms.
            'starts_at' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['starts_at']],
            // Editable on any kind: it pre-fills the transaction form and nothing that calculates
            // reads it. A deposit's own currency travels with its terms, and this sets both.
            'currency_code' => ['sometimes', ...$deposit['currency_code']],

            // Deposit terms, accepted only for an account that is one. A whole replacement rather
            // than a partial edit: the rate periods must cover the term without a gap, and that is
            // provable for a replacement and not for a patch.
            'principal_minor' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['principal_minor']],
            // Not the shared rule's `after:starts_at`: an edit may leave the start out, and the action
            // checks the pair against the stored start instead.
            'maturity_at' => [$isDeposit ? 'sometimes' : 'prohibited', 'date', 'after:today'],
            'annual_rate' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['annual_rate']],
            'rate_type' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['rate_type']],
            'interest_method' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['interest_method']],
            'payment_frequency' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['payment_frequency']],
            'day_count_convention' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['day_count_convention']],
            'funding_account_id' => [$isDeposit ? 'sometimes' : 'prohibited', ...$deposit['funding_account_id']],
        ]);

        try {
            $update($model, new UpdateFinancialAccountInput(
                actorId: $this->actorId($request),
                displayName: $isDeposit && array_key_exists('bank_id', $validated)
                    ? $banks->find(self::str($validated, 'bank_id'))?->name
                    : $this->optional($validated, 'display_name'),
                currencyCode: $this->optional($validated, 'currency_code'),
                holdingAmountMinor: array_key_exists('holding_amount_minor', $validated)
                    ? (int) self::str($validated, 'holding_amount_minor')
                    : null,
                depositTerms: $this->depositTerms($validated, $model),
            ));
        } catch (DepositClosedException $exception) {
            // 409: nothing about the request is malformed, the deposit is simply over.
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'DEPOSIT_CLOSED'], 409);
        } catch (DepositTermsLockedException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'reason' => 'DEPOSIT_TERMS_LOCKED'], 422);
        }

        return response()->json(['id' => $model->id]);
    }

    /**
     * The replacement terms, or null when this request changed none of them.
     *
     * Every deposit field is read from the current row when the request omitted it, so a PATCH that
     * only moves the maturity date keeps the rate, method and frequency it already had.
     *
     * @param  array<string, mixed>  $validated
     */
    private function depositTerms(array $validated, FinancialAccount $account): ?DepositTermsInput
    {
        $terms = $account->depositTerms;

        if ($terms === null) {
            return null;
        }

        // `starts_at` and `currency_code` included: they travel with the terms, and leaving them out
        // meant a request changing only the start date was treated as changing nothing.
        $depositKeys = [
            'principal_minor', 'starts_at', 'currency_code', 'maturity_at', 'annual_rate', 'rate_type',
            'interest_method', 'payment_frequency', 'day_count_convention', 'funding_account_id', 'bank_id',
        ];

        $touched = array_filter($depositKeys, static fn (string $key): bool => array_key_exists($key, $validated));

        if ($touched === []) {
            return null;
        }

        $maturityAt = array_key_exists('maturity_at', $validated)
            ? self::str($validated, 'maturity_at')
            : $terms->maturity_at->toDateString();


        // The current rate, unless the request supplied a new one. Read from the periods rather
        // than stored on the terms, because a deposit has a *series* of rates.
        $currentPeriod = $account->depositRatePeriods->last();

        $annualRate = array_key_exists('annual_rate', $validated)
            ? self::str($validated, 'annual_rate')
            : ($currentPeriod === null ? '0' : $currentPeriod->annual_rate_decimal);

        // The replacement's own start date, so the single rate period the screen sends covers
        // exactly the span the validator will check.
        $startsAt = array_key_exists('starts_at', $validated)
            ? self::str($validated, 'starts_at')
            : $terms->starts_at->toDateString();

        return new DepositTermsInput(
            principalMinor: array_key_exists('principal_minor', $validated)
                ? (int) self::str($validated, 'principal_minor')
                : $terms->principal_minor,
            startsAt: $startsAt,
            currencyCode: array_key_exists('currency_code', $validated)
                ? strtoupper(self::str($validated, 'currency_code'))
                : $terms->currency_code,
            maturityAt: $maturityAt,
            rateType: array_key_exists('rate_type', $validated)
                ? RateType::from(self::str($validated, 'rate_type'))
                : $terms->rate_type,
            interestMethod: array_key_exists('interest_method', $validated)
                ? InterestMethod::from(self::str($validated, 'interest_method'))
                : $terms->interest_method,
            paymentFrequency: array_key_exists('payment_frequency', $validated)
                ? PaymentFrequency::from(self::str($validated, 'payment_frequency'))
                : $terms->payment_frequency,
            dayCountConvention: array_key_exists('day_count_convention', $validated)
                ? DayCountConvention::from(self::str($validated, 'day_count_convention'))
                : $terms->day_count_convention,
            ratePeriods: [new DepositRatePeriodInput(
                validFrom: $startsAt,
                validTo: $maturityAt,
                annualRateDecimal: $annualRate,
            )],
            fundingAccountId: array_key_exists('funding_account_id', $validated)
                ? $this->optional($validated, 'funding_account_id')
                : $terms->funding_account_id,
            bankId: array_key_exists('bank_id', $validated)
                ? $this->optional($validated, 'bank_id')
                : $terms->bank_id,
        );
    }

    /** @param array<string, mixed> $validated */
    private function optional(array $validated, string $key): ?string
    {
        if (! array_key_exists($key, $validated)) {
            return null;
        }

        $value = $validated[$key];

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $validated */
    private static function str(array $validated, string $key): string
    {
        $value = $validated[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
