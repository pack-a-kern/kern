<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Banks\Queries\BankDirectory;
use App\Domain\Wallet\Actions\OpenFinancialAccount;
use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\DataTransferObjects\DepositTermsInput;
use App\Domain\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Http\Controllers\Controller;
use App\Rules\StreamSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/subjects/{subject}/wallet/accounts` — add an account by hand.
 *
 * Thin over `App\Domain\Wallet\Actions\OpenFinancialAccount`: the action already encrypts the
 * display name and IBAN under the subject's own DEK and derives the IBAN's blind index. Nothing is
 * re-implemented here, so an account created from this screen is byte-for-byte the same shape as
 * one created by a future import or bank sync.
 *
 * The subject comes from `TenantContext`, not from the request body. The route already bound it,
 * and accepting a `tax_subject_id` field would let a caller write into a subject the URL did not
 * authorise — the bound context is the one the middleware checked membership against.
 */
final class OpenAccountController extends Controller
{
    public function __invoke(
        Request $request,
        OpenFinancialAccount $open,
        TenantContext $tenant,
        ConfigRepository $config,
        BankDirectory $banks,
    ): JsonResponse {
        $validated = $request->validate([
            // A deposit is named after its bank, chosen from the imported list rather than typed —
            // so its name is never sent, and its bank always is.
            'display_name' => [
                'required_unless:kind,' . AccountKind::TERM_DEPOSIT->value,
                'prohibited_if:kind,' . AccountKind::TERM_DEPOSIT->value,
                'string',
                'max:120',
            ],
            'bank_id' => [
                'required_if:kind,' . AccountKind::TERM_DEPOSIT->value,
                'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value,
                'uuid',
                Rule::exists('ext_banks', 'id')->where('active', true),
            ],
            'kind' => ['required', Rule::enum(AccountKind::class)],
            // ISO 4217, and optional: this is the currency the transaction form pre-fills, not a
            // property of the source. A broker or a crypto exchange holds several at once and
            // should not be made to pick one. Uppercase enforced rather than normalised, so "eur"
            // and "EUR" cannot become two different defaults.
            'currency_code' => [
                'required_if:kind,' . AccountKind::CASH->value,
                'required_if:kind,' . AccountKind::TERM_DEPOSIT->value,
                'sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/',
            ],
            // A cash holding is a declared value; every other kind receives transactions instead.
            // Both directions in the validator, so the caller gets a field-level message rather
            // than a 500 from the action's own invariant.
            'holding_amount_minor' => [
                'required_if:kind,' . AccountKind::CASH->value,
                'prohibited_unless:kind,' . AccountKind::CASH->value,
                'integer',
            ],
            // A deposit's own start date: the day interest begins accruing, and the left edge of
            // the span its rate periods must cover.
            'starts_at' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'date'],

            // Deposit terms. Required together when `kind` is TERM_DEPOSIT and rejected otherwise —
            // `required_if`/`prohibited_unless` express both directions in the validator, so the
            // caller gets a field-level message instead of a 500 from the action's own invariant.
            'principal_minor' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'integer', 'min:1'],
            // After today as well as after the start: maturity closes a deposit, so one entered
            // already matured would be settled the night it was typed in. Here, on what a person
            // enters, and not in `OpenFinancialAccount`: a deposit that matured in the past is a
            // perfectly valid fact for recording a previous year's interest by other means.
            'maturity_at' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'date', 'after:starts_at', 'after:today'],
            // A decimal string, never a float: binary floating point cannot represent 5.40, and
            // this figure multiplies into a tax return. Percent per annum.
            'annual_rate' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'rate_type' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, Rule::enum(RateType::class)],
            'interest_method' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, Rule::enum(InterestMethod::class)],
            'payment_frequency' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, Rule::enum(PaymentFrequency::class)],
            // Optional, and the screen never sends it: the convention comes from the bank's
            // contract, not from a form, so the default lives in `config/wallet.php`.
            'day_count_convention' => ['sometimes', Rule::enum(DayCountConvention::class)],
            'funding_account_id' => ['sometimes', 'nullable', 'uuid', new StreamSource()],
        ]);

        $kind = AccountKind::from((string) $validated['kind']);

        $account = $open(new OpenFinancialAccountInput(
            taxSubjectId: $tenant->currentTenantId(),
            kind: $kind,
            displayName: $kind === AccountKind::TERM_DEPOSIT
                ? (string) $banks->find(self::str($validated, 'bank_id'))?->name
                : self::str($validated, 'display_name'),
            currencyCode: $this->nullableString($validated, 'currency_code'),
            holdingAmountMinor: array_key_exists('holding_amount_minor', $validated)
                ? self::int($validated, 'holding_amount_minor')
                : null,
            depositTerms: $kind === AccountKind::TERM_DEPOSIT
                ? $this->depositTerms($validated, $config)
                : null,
            actorId: $this->actorId($request),
        ));

        $key = $account->getKey();

        return response()->json(['id' => is_scalar($key) ? (string) $key : ''], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function nullableString(array $validated, string $key): ?string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
    private function depositTerms(array $validated, ConfigRepository $config): DepositTermsInput
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
            // A deposit is denominated in one currency by definition; the source it sits in may not
            // be, so the principal carries its own.
            currencyCode: strtoupper(self::str($validated, 'currency_code')),
            maturityAt: $maturityAt,
            rateType: RateType::from(self::str($validated, 'rate_type')),
            interestMethod: InterestMethod::from(self::str($validated, 'interest_method')),
            paymentFrequency: PaymentFrequency::from(self::str($validated, 'payment_frequency')),
            dayCountConvention: $this->dayCountConvention($validated, $config),
            ratePeriods: [new DepositRatePeriodInput(
                validFrom: $startsAt,
                validTo: $maturityAt,
                annualRateDecimal: self::str($validated, 'annual_rate'),
            )],
            fundingAccountId: $this->nullableString($validated, 'funding_account_id'),
            bankId: $this->nullableString($validated, 'bank_id'),
        );
    }

    /** @param array<string, mixed> $validated */
    private static function str(array $validated, string $key): string
    {
        $value = $validated[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $validated */
    private static function int(array $validated, string $key): int
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
    private function dayCountConvention(array $validated, ConfigRepository $config): DayCountConvention
    {
        $requested = $validated['day_count_convention'] ?? null;

        if (is_string($requested) && $requested !== '') {
            return DayCountConvention::from($requested);
        }

        $configured = $config->get('wallet.deposit.default_day_count');

        return (is_string($configured) ? DayCountConvention::tryFrom($configured) : null)
            ?? DayCountConvention::cases()[0];
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
