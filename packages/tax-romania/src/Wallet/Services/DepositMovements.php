<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\DepositTerms;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;

/**
 * Every transaction a deposit produces, written the same way.
 *
 * A deposit moves money three times: out of the account that funds it, as interest into itself, and
 * back to the funding account when it closes. None of those is typed by anybody — they are its
 * terms taking effect — so all three are `SYSTEM` rows, classified as they are written (`TRANSFER`
 * or `INTEREST`, from `config/reference/tax_categories.php`) rather than left in the worklist for someone to
 * say what they obviously are.
 *
 * Described by the deposit's own name rather than by a sentence. A literal like "Dobândă depozit"
 * stored in the ledger is one language frozen into data; the category already says what the row is,
 * in whatever language the screen reads it in.
 *
 * Written with the tenant scope lifted, because two of the three are written by the nightly
 * settlement, where no `TenantContext` exists. Every caller names the subject explicitly.
 */
final readonly class DepositMovements
{
    public const string TRANSFER = 'TRANSFER';

    /*
     * Both values are tax category codes from `config/reference/tax_categories.php`, not names this
     * class is free to choose: `PostMaturedDepositInterest` writes this straight into
     * `transaction_splits.category_code`, and a value the catalogue does not know renders in the
     * interface as the raw string and is refused by every save a person makes. So a category renamed
     * there has to be renamed here in the same commit.
     */
    public const string INTEREST = 'DIVIDENDS_INTEREST';

    /**
     * Makes the funding transaction match the deposit's terms as they are now.
     *
     * Created when a funding account is named, updated when the principal, start date, currency or
     * funding account change, and removed when the funding account is cleared — so the funding
     * account always shows exactly the money that left it for this deposit, and nothing else.
     */
    public function syncFunding(FinancialAccount $deposit, DepositTerms $terms): void
    {
        $existing = $terms->funding_transaction_id === null
            ? null
            : FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->find($terms->funding_transaction_id);

        if ($terms->funding_account_id === null) {
            $this->remove($terms->funding_transaction_id);
            $terms->funding_transaction_id = null;
            $terms->save();

            return;
        }

        if ($existing === null) {
            $created = $this->write(
                subjectId: $terms->tax_subject_id,
                accountId: $terms->funding_account_id,
                bookedAt: $terms->starts_at->toDateString(),
                amountMinor: -$terms->principal_minor,
                currencyCode: $terms->currency_code,
                description: self::nameOf($deposit),
                category: self::TRANSFER,
            );

            $terms->funding_transaction_id = self::id($created);
            $terms->save();

            return;
        }

        $existing->fill([
            'account_id' => $terms->funding_account_id,
            'booked_at' => $terms->starts_at->toDateString(),
            'amount_minor' => -$terms->principal_minor,
            'currency_code' => strtoupper($terms->currency_code),
            'description_ciphertext' => self::nameOf($deposit),
        ]);
        $existing->save();

        // The split partitions the transaction, so it has to follow the amount.
        TransactionSplit::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('transaction_id', self::id($existing))
            ->update(['amount_minor' => -$terms->principal_minor]);
    }

    public function write(
        string $subjectId,
        string $accountId,
        string $bookedAt,
        int $amountMinor,
        string $currencyCode,
        string $description,
        string $category,
    ): FinancialTransaction {
        return $this->writeSplit($subjectId, $accountId, $bookedAt, $currencyCode, $description, [$category => $amountMinor]);
    }

    /**
     * One movement whose money is several things — a sale's proceeds, the cost coming back and the gain on
     * it — a split each, by category. The movement is their sum; a split of nought is not written.
     *
     * @param non-empty-array<string, int> $splits amount in minor units, by category
     */
    public function writeSplit(
        string $subjectId,
        string $accountId,
        string $bookedAt,
        string $currencyCode,
        string $description,
        array $splits,
    ): FinancialTransaction {
        $amountMinor = array_sum($splits);
        $transaction = FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->create([
            'tax_subject_id' => $subjectId,
            'account_id' => $accountId,
            'booked_at' => $bookedAt,
            'amount_minor' => $amountMinor,
            'currency_code' => strtoupper($currencyCode),
            'description_ciphertext' => $description,
            'counterparty_ciphertext' => '',
            'classification_status' => TransactionClassificationStatus::CLASSIFIED,
            'source' => RecordSource::SYSTEM,
        ]);

        foreach (array_filter($splits) as $category => $amount) {
            TransactionSplit::query()->withoutGlobalScope(TenantScope::class)->create([
                'tax_subject_id' => $subjectId,
                'transaction_id' => self::id($transaction),
                'amount_minor' => $amount,
                'category_code' => $category,
            ]);
        }

        return $transaction;
    }

    /** A deposit movement, and the split that classified it. Nothing when there is nothing to remove. */
    public function remove(?string $transactionId): void
    {
        if ($transactionId === null) {
            return;
        }

        TransactionSplit::query()->withoutGlobalScope(TenantScope::class)->where('transaction_id', $transactionId)->delete();
        FinancialTransaction::query()->withoutGlobalScope(TenantScope::class)->whereKey($transactionId)->delete();
    }

    public static function nameOf(?FinancialAccount $deposit): string
    {
        return $deposit === null ? '' : trim((string) $deposit->display_name_ciphertext);
    }

    public static function id(FinancialTransaction $transaction): string
    {
        $id = $transaction->getKey();

        return is_scalar($id) ? (string) $id : '';
    }
}
