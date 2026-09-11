<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Domain\Subjects\Tenancy\TenantContext;
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
    ): JsonResponse {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(AccountKind::class)],
            // ISO 4217. Uppercase enforced rather than normalised: a currency is part of an
            // account's identity, and silently rewriting user input is how "eur" and "EUR" become
            // two accounts.
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'institution_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Loosely checked here on purpose: IBAN structure varies by country, and the value is
            // encrypted at rest with a blind index for matching. A wrong-but-plausible IBAN is a
            // data-entry problem the owner can see and fix, not something to reject on a guess.
            'iban' => ['sometimes', 'nullable', 'string', 'max:34'],
            'opened_at' => ['sometimes', 'nullable', 'date'],

            // Deposit terms. Required together when `kind` is TERM_DEPOSIT and rejected otherwise —
            // `required_if`/`prohibited_unless` express both directions in the validator, so the
            // caller gets a field-level message instead of a 500 from the action's own invariant.
            'principal_minor' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'integer', 'min:1'],
            'maturity_at' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'date', 'after:opened_at'],
            // A decimal string, never a float: binary floating point cannot represent 5.40, and
            // this figure multiplies into a tax return. Percent per annum.
            'annual_rate' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value, 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'rate_type' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, Rule::enum(RateType::class)],
            'interest_method' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, Rule::enum(InterestMethod::class)],
            'payment_frequency' => ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, Rule::enum(PaymentFrequency::class)],
            // Optional, and the screen never sends it: the convention comes from the bank's
            // contract, not from a form, so the default lives in `config/wallet.php`.
            'day_count_convention' => ['sometimes', Rule::enum(DayCountConvention::class)],
            'auto_renewal' => ['sometimes', 'boolean'],
            'funding_account_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $kind = AccountKind::from((string) $validated['kind']);
        $openedAt = $this->nullableString($validated, 'opened_at');

        $account = $open(new OpenFinancialAccountInput(
            taxSubjectId: $tenant->currentSubjectId(),
            kind: $kind,
            displayName: (string) $validated['display_name'],
            currencyCode: (string) $validated['currency_code'],
            institutionName: $this->nullableString($validated, 'institution_name'),
            iban: $this->nullableString($validated, 'iban'),
            openedAt: $openedAt,
            depositTerms: $kind === AccountKind::TERM_DEPOSIT
                ? $this->depositTerms($request, $validated, $openedAt, $config)
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
    private function depositTerms(Request $request, array $validated, ?string $openedAt, ConfigRepository $config): DepositTermsInput
    {
        // Narrowed through `self::str()`/`self::int()` rather than cast directly: `$validated`
        // is `array<string, mixed>`, and a bare cast on `mixed` is a static-analysis error and, on
        // an array, a runtime one. The validator has already guaranteed these are present and
        // well-shaped; this only makes that guarantee visible to the type system.
        $maturityAt = self::str($validated, 'maturity_at');

        return new DepositTermsInput(
            principalMinor: self::int($validated, 'principal_minor'),
            maturityAt: $maturityAt,
            rateType: RateType::from(self::str($validated, 'rate_type')),
            interestMethod: InterestMethod::from(self::str($validated, 'interest_method')),
            paymentFrequency: PaymentFrequency::from(self::str($validated, 'payment_frequency')),
            dayCountConvention: $this->dayCountConvention($validated, $config),
            ratePeriods: [new DepositRatePeriodInput(
                validFrom: (string) $openedAt,
                validTo: $maturityAt,
                annualRateDecimal: self::str($validated, 'annual_rate'),
            )],
            autoRenewal: $request->boolean('auto_renewal'),
            fundingAccountId: $this->nullableString($validated, 'funding_account_id'),
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
