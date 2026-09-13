<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Wallet\Actions\UpdateFinancialAccount;
use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\DataTransferObjects\DepositTermsInput;
use App\Domain\Wallet\DataTransferObjects\UpdateFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
final class UpdateAccountController extends Controller
{
    public function __invoke(
        Request $request,
        string $subject,
        string $account,
        UpdateFinancialAccount $update,
    ): JsonResponse {
        // Tenant-scoped: another subject's account is not found rather than edited.
        $model = FinancialAccount::query()->findOrFail($account);
        $isDeposit = $model->kind === AccountKind::TERM_DEPOSIT;

        $validated = $request->validate([
            // Rejected outright rather than ignored: `kind` decides whether transactions may be
            // recorded against this source at all, so changing it would reinterpret what is already
            // stored. Silently dropping the field would let a caller believe the change took
            // effect. Getting it wrong means deleting the source and opening the right one.
            'kind' => ['prohibited'],

            'display_name' => ['sometimes', 'string', 'max:120'],
            'holding_amount_minor' => [
                $model->kind === AccountKind::CASH ? 'sometimes' : 'prohibited',
                'integer',
            ],
            // A deposit's own dates and currency travel with its terms.
            'starts_at' => [$isDeposit ? 'sometimes' : 'prohibited', 'date'],
            // Editable on any kind: it pre-fills the transaction form and nothing that calculates
            // reads it. A deposit's own currency travels with its terms, and this sets both.
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],

            // Deposit terms, accepted only for an account that is one. A whole replacement rather
            // than a partial edit: the rate periods must cover the term without a gap, and that is
            // provable for a replacement and not for a patch.
            'principal_minor' => [$isDeposit ? 'sometimes' : 'prohibited', 'integer', 'min:1'],
            'maturity_at' => [$isDeposit ? 'sometimes' : 'prohibited', 'date'],
            'annual_rate' => [$isDeposit ? 'sometimes' : 'prohibited', 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'rate_type' => [$isDeposit ? 'sometimes' : 'prohibited', Rule::enum(RateType::class)],
            'interest_method' => [$isDeposit ? 'sometimes' : 'prohibited', Rule::enum(InterestMethod::class)],
            'payment_frequency' => [$isDeposit ? 'sometimes' : 'prohibited', Rule::enum(PaymentFrequency::class)],
            'day_count_convention' => [$isDeposit ? 'sometimes' : 'prohibited', Rule::enum(DayCountConvention::class)],
            'auto_renewal' => [$isDeposit ? 'sometimes' : 'prohibited', 'boolean'],
            'funding_account_id' => [$isDeposit ? 'sometimes' : 'prohibited', 'nullable', 'uuid'],
        ]);

        $update($model, new UpdateFinancialAccountInput(
            actorId: $this->actorId($request),
            displayName: $this->optional($validated, 'display_name'),
            currencyCode: $this->optional($validated, 'currency_code'),
            holdingAmountMinor: array_key_exists('holding_amount_minor', $validated)
                ? (int) self::str($validated, 'holding_amount_minor')
                : null,
            depositTerms: $this->depositTerms($request, $validated, $model),
        ));

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
    private function depositTerms(Request $request, array $validated, FinancialAccount $account): ?DepositTermsInput
    {
        $terms = $account->depositTerms;

        if ($terms === null) {
            return null;
        }

        $depositKeys = [
            'principal_minor', 'maturity_at', 'annual_rate', 'rate_type',
            'interest_method', 'payment_frequency', 'day_count_convention',
            'auto_renewal', 'funding_account_id',
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
            autoRenewal: array_key_exists('auto_renewal', $validated)
                ? $request->boolean('auto_renewal')
                : $terms->auto_renewal,
            fundingAccountId: array_key_exists('funding_account_id', $validated)
                ? $this->optional($validated, 'funding_account_id')
                : $terms->funding_account_id,
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
