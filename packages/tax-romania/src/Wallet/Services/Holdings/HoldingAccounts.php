<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Holdings;

use Illuminate\Database\UniqueConstraintViolationException;
use Kern\TaxRomania\Wallet\Actions\OpenFinancialAccount;
use Kern\TaxRomania\Wallet\DataTransferObjects\OpenFinancialAccountInput;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;

/**
 * The money account a holding's coupons, dividends, repayments and sales are paid into (ADR 0025 §9).
 *
 * A person never opens it: there is one per taxpayer, place held and currency, found here or opened on
 * the first holding that needs it, named for the place and the currency. Two holdings added at once open
 * it once — the unique index on (`tax_subject_id`, `held_at`, `currency_code`) refuses the second insert,
 * and the loser finds what the winner made.
 */
final readonly class HoldingAccounts
{
    public function __construct(private OpenFinancialAccount $open)
    {
    }

    public function for(string $subjectId, HeldAt $heldAt, string $currency, ?string $actorId): FinancialAccount
    {
        try {
            return $this->find($subjectId, $heldAt, $currency) ?? ($this->open)(new OpenFinancialAccountInput(
                taxSubjectId: $subjectId,
                kind: AccountKind::INVESTMENTS,
                displayName: __('tax-romania::wallet.holding_account', ['place' => __('tax-romania::wallet.held_at.' . $heldAt->value), 'currency' => $currency]),
                currencyCode: $currency,
                actorId: $actorId,
                heldAt: $heldAt,
            ));
        } catch (UniqueConstraintViolationException $race) {
            return $this->find($subjectId, $heldAt, $currency) ?? throw $race;
        }
    }

    private function find(string $subjectId, HeldAt $heldAt, string $currency): ?FinancialAccount
    {
        // By the taxpayer named, not by the tenant scope alone: the lookup the unique index is on.
        return FinancialAccount::query()
            ->where('tax_subject_id', $subjectId)
            ->where('kind', AccountKind::INVESTMENTS->value)
            ->where('held_at', $heldAt->value)
            ->where('currency_code', $currency)
            ->first();
    }
}
