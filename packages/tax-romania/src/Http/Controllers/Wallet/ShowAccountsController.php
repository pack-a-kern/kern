<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\TaxRomania\Wallet\Queries\AccountSummary;
use Kern\TaxRomania\Wallet\Queries\WalletQuery;

/**
 * `GET /api/v1/subjects/{subject}/wallet/accounts` — every account for the active tax subject.
 *
 * Closed accounts are excluded unless asked for. They are never deleted — a closed account's
 * transactions still belong to past tax years and must stay citable — but a list of live accounts
 * is what the screen is for.
 *
 * No pagination: a person has accounts in the tens, and paging a list that short costs a round trip
 * and a cursor to solve a problem nobody has.
 */
final class ShowAccountsController
{
    public function __invoke(Request $request, WalletQuery $wallet, ConfigRepository $config): JsonResponse
    {
        // No `include_closed` any more: closing an account was a banking state this application
        // had no use for, and a source is either there or deleted.
        $accounts = $wallet->accounts();

        return response()->json([
            'accounts' => array_map(
                static fn (AccountSummary $account): array => $account->toArray(),
                $accounts,
            ),
            // Deposit form options live with accounts because a term deposit *is* an
            // account — there is one form, and it grows when the kind calls for it.
            'form_options' => [
                'terms_months' => $this->termsMonths($config),
            ],
        ]);
    }

    /**
     * Terms the deposit form offers, in months, from `config/wallet.php`.
     *
     * Filtered rather than trusted: a malformed entry should drop one option, not put a nonsense
     * term in the select.
     *
     * @return list<int>
     */
    private function termsMonths(ConfigRepository $config): array
    {
        $terms = $config->get('wallet.deposit.terms_months');

        return array_values(array_filter(
            is_array($terms) ? $terms : [],
            static fn (mixed $months): bool => is_int($months) && $months > 0,
        ));
    }
}
