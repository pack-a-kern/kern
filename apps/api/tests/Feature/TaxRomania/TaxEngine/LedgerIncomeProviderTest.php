<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\TaxEngine\LedgerIncomeProviderTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\TaxRomania\Database\Factories\FinancialAccountFactory;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\LedgerIncomeEntry;
use Kern\TaxRomania\Wallet\Providers\EloquentLedgerIncomeProvider;

/**
 * The ledger's income for one fiscal year, as the tax engine reads it (`EloquentLedgerIncomeProvider`).
 *
 * Rows inserted directly: this is about which splits a year holds and how many queries finding them
 * costs, and the model would drag in encryption for neither.
 */
function bookIncome(string $subjectId, string $accountId, string $bookedAt, string $category, int $amountMinor, string $currency = 'RON'): string
{
    $transactionId = (string) Str::uuid7();

    DB::table('financial_transactions')->insert([
        'id' => $transactionId, 'tax_subject_id' => $subjectId, 'account_id' => $accountId,
        'booked_at' => $bookedAt, 'amount_minor' => $amountMinor, 'currency_code' => $currency,
        'description_ciphertext' => 'x', 'counterparty_ciphertext' => 'x',
        'classification_status' => 'CLASSIFIED', 'source' => 'MANUAL', 'created_at' => now(),
    ]);

    $splitId = (string) Str::uuid7();

    DB::table('transaction_splits')->insert([
        'id' => $splitId, 'tax_subject_id' => $subjectId, 'transaction_id' => $transactionId,
        'amount_minor' => $amountMinor, 'category_code' => $category,
    ]);

    return $splitId;
}

it('reads one year of the asked categories in two queries, whatever the ledger holds', function (): void {
    $subject = assessableSubject();
    $account = FinancialAccountFactory::new()->createOne(['tax_subject_id' => $subject->id]);

    // The year's edges, the years either side, and a category nobody asked for.
    bookIncome($subject->id, $account->id, '2024-12-31', 'RENT', 100);
    $first = bookIncome($subject->id, $account->id, '2025-01-01', 'RENT', 200, 'EUR');
    $last = bookIncome($subject->id, $account->id, '2025-12-31', 'DIVIDEND', 300);
    bookIncome($subject->id, $account->id, '2025-06-01', 'SALARY', 400);
    bookIncome($subject->id, $account->id, '2026-01-01', 'RENT', 500);

    DB::enableQueryLog();

    $entries = (new EloquentLedgerIncomeProvider())->incomeIn($subject->id, 2025, ['RENT', 'DIVIDEND']);

    // Each split used to fetch its own transaction, from every year, and drop the other years after.
    expect(DB::getQueryLog())->toHaveCount(2)
        ->and(array_map(static fn (LedgerIncomeEntry $entry): array => [$entry->sourceId, $entry->amountMinor, $entry->currencyCode, $entry->bookedAt->format('Y-m-d')], $entries))
        ->toEqualCanonicalizing([
            [$first, 200, 'EUR', '2025-01-01'],
            [$last, 300, 'RON', '2025-12-31'],
        ]);
});
