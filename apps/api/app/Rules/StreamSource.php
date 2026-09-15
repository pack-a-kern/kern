<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Wallet\Models\FinancialAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A source of this taxpayer's that receives transactions — not a cash holding, not a deposit.
 *
 * For the account a deposit is funded from and settles back into: a holding's entries come from its
 * own declaration, so writing a deposit's money into one would detach the ledger from the thing it
 * describes.
 *
 * Tenant-scoped through the model: an id belonging to another taxpayer is "not found", never used.
 */
final class StreamSource implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $account = FinancialAccount::query()->find(is_string($value) ? $value : '');

        if ($account === null) {
            $fail('The selected source does not exist.');

            return;
        }

        if ($account->kind->isHolding()) {
            $fail("A {$account->kind->value} source does not receive transactions.");
        }
    }
}
