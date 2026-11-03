<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount;
use Kern\TaxRomania\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;

/**
 * `POST /api/v1/subjects/{subject}/wallet/accounts` — add an account by hand.
 *
 * Thin over `Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount`: the action already encrypts the
 * display name and IBAN under the subject's own DEK and derives the IBAN's blind index. Nothing is
 * re-implemented here, so an account created from this screen is byte-for-byte the same shape as
 * one created by a future import or bank sync.
 *
 * The subject comes from `TenantContext`, not from the request body. The route already bound it,
 * and accepting a `tax_subject_id` field would let a caller write into a subject the URL did not
 * authorise — the bound context is the one the middleware checked membership against.
 */
final class OpenAccountController
{
    public function __invoke(
        Request $request,
        OpenFinancialAccount $open,
        TenantContext $tenant,
        DepositFields $fields,
    ): JsonResponse {
        $deposit = DepositFields::rules();
        // Asked for only when the kind is a deposit, and refused on any other — both directions in
        // the validator, so the caller gets a field-level message instead of a 500 from the action's
        // own invariant. The constraints themselves are the deposit's, shared with the CSV import.
        $depositOnly = ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value, 'prohibited_unless:kind,' . AccountKind::TERM_DEPOSIT->value];
        $depositRequired = ['required_if:kind,' . AccountKind::TERM_DEPOSIT->value];

        $validated = $request->validate([
            'display_name' => [
                'required_unless:kind,' . AccountKind::TERM_DEPOSIT->value,
                'prohibited_if:kind,' . AccountKind::TERM_DEPOSIT->value,
                'string',
                'max:120',
            ],
            'bank_id' => [...$depositOnly, ...$deposit['bank_id']],
            'kind' => ['required', Rule::enum(AccountKind::class)],
            // ISO 4217, and optional: this is the currency the transaction form pre-fills, not a
            // property of the source. A broker or a crypto exchange holds several at once and
            // should not be made to pick one. Uppercase enforced rather than normalised, so "eur"
            // and "EUR" cannot become two different defaults.
            'currency_code' => [
                'required_if:kind,' . AccountKind::CASH->value,
                ...$depositRequired,
                'sometimes', 'nullable', ...$deposit['currency_code'],
            ],
            // A cash holding is a declared value; every other kind receives transactions instead.
            'holding_amount_minor' => [
                'required_if:kind,' . AccountKind::CASH->value,
                'prohibited_unless:kind,' . AccountKind::CASH->value,
                'integer',
            ],
            'starts_at' => [...$depositOnly, ...$deposit['starts_at']],
            'principal_minor' => [...$depositOnly, ...$deposit['principal_minor']],
            'maturity_at' => [...$depositOnly, ...$deposit['maturity_at']],
            'annual_rate' => [...$depositOnly, ...$deposit['annual_rate']],
            'rate_type' => [...$depositRequired, ...$deposit['rate_type']],
            'interest_method' => [...$depositRequired, ...$deposit['interest_method']],
            'payment_frequency' => [...$depositRequired, ...$deposit['payment_frequency']],
            'day_count_convention' => $deposit['day_count_convention'],
            'funding_account_id' => $deposit['funding_account_id'],
        ]);

        $kind = AccountKind::from((string) $validated['kind']);

        $account = $open(new OpenFinancialAccountInput(
            taxSubjectId: $tenant->currentTenantId(),
            kind: $kind,
            displayName: $kind === AccountKind::TERM_DEPOSIT
                ? $fields->displayName($validated)
                : DepositFields::str($validated, 'display_name'),
            currencyCode: DepositFields::nullableString($validated, 'currency_code'),
            holdingAmountMinor: array_key_exists('holding_amount_minor', $validated)
                ? DepositFields::int($validated, 'holding_amount_minor')
                : null,
            depositTerms: $kind === AccountKind::TERM_DEPOSIT ? $fields->terms($validated) : null,
            actorId: $this->actorId($request),
        ));

        $key = $account->getKey();

        return response()->json(['id' => is_scalar($key) ? (string) $key : ''], 201);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : null;
    }
}
