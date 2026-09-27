<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\Wallet\DataTransferObjects\TransactionSplitInput;
use Kern\TaxRomania\Wallet\Enums\MoneyDirection;
use Kern\TaxRomania\Wallet\Enums\ProposalSource;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Exceptions\SystemTransactionException;
use Kern\TaxRomania\Wallet\Exceptions\TransactionSplitMismatchException;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;
use Kern\TaxRomania\Wallet\Services\CounterpartyRules;
use Kern\TaxRomania\Wallet\Services\TaxCategoryCatalogue;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Says what a transaction was.
 *
 * The step between a ledger and a tax return. A bank says 4.500 RON arrived from Popescu Ion; only a
 * person can say whether that is rent, a friend repaying a loan, or a dividend — and the three are
 * taxed completely differently, or not at all. Everything downstream reads this answer.
 *
 * ## Replace, never append
 *
 * Classifying a transaction replaces its splits entirely. A correction is the normal case — someone
 * labelled a payment `OTHER_INCOME` and then realised it was `FREELANCE` — and merging the old answer
 * with the new one would leave a transaction claiming to be both, with amounts that no longer add up.
 * Sending an empty list is a legitimate act: it returns the transaction to the unclassified worklist.
 *
 * ## The amounts must add up, exactly
 *
 * Splits partition a transaction; they do not annotate it. If they summed to less, the remainder
 * would be income nobody has an answer for, silently omitted from a return — so a mismatch is
 * refused rather than stored and discovered later.
 */
final readonly class ClassifyTransaction
{
    public function __construct(
        private WalletAudit $audit,
        private TaxCategoryCatalogue $categories,
        private CounterpartyRules $rules,
    ) {
    }

    /**
     * @param  list<TransactionSplitInput>  $splits  empty returns the transaction to unclassified
     */
    public function __invoke(
        FinancialTransaction $transaction,
        array $splits,
        ?string $actorId = null,
    ): FinancialTransaction {
        // Produced from a deposit's terms. The change belongs on the deposit, which rewrites it.
        if ($transaction->source === RecordSource::SYSTEM) {
            throw SystemTransactionException::forTransaction($transaction->id);
        }

        $this->assertSplitsMatchTotal($transaction->amount_minor, $splits);

        $this->categories->assertSplitsFit($transaction->amount_minor, $splits);

        DB::transaction(function () use ($transaction, $splits): void {
            TransactionSplit::query()->where('transaction_id', $transaction->id)->delete();

            foreach ($splits as $split) {
                TransactionSplit::create([
                    'tax_subject_id' => $transaction->tax_subject_id,
                    'transaction_id' => $transaction->id,
                    'amount_minor' => $split->amountMinor,
                    'category_code' => $split->categoryCode,
                    'country_code' => $split->countryCode,
                ]);
            }

            $transaction->classification_status = $splits === []
                ? TransactionClassificationStatus::UNCLASSIFIED
                : TransactionClassificationStatus::CLASSIFIED;
            $transaction->save();
        });

        // What a person just said this counterparty was, kept for the next statement that names it.
        // Clearing the answer clears nothing remembered: "I am not sure yet" is not a decision either.
        if ($splits !== []) {
            $this->rules->remember(
                index: $transaction->counterparty_blind_index,
                counterparty: $transaction->counterparty_ciphertext,
                direction: MoneyDirection::ofAmount($transaction->amount_minor),
                categoryCode: $splits[0]->categoryCode,
                countryCode: $splits[0]->countryCode,
                tagIds: array_values(array_map(
                    static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
                    $transaction->tags()->pluck('tags.id')->all(),
                )),
                source: ProposalSource::PERSON,
            );
        }

        $this->audit->record(
            subjectId: $transaction->tax_subject_id,
            actorId: $actorId,
            eventType: WalletAuditEvent::TRANSACTION_CLASSIFIED->value,
            resourceType: WalletAuditEvent::TRANSACTION_CLASSIFIED->resourceType(),
            resourceId: $transaction->id,
            payload: [
                // The categories and their countries, never the amounts: what something *was* is
                // the decision worth recording, and the figures are already on the rows. Joined
                // into one string because the audit payload is a flat map of scalars — it is
                // hashed, not queried.
                'splits' => implode(',', array_map(
                    static fn (TransactionSplitInput $split): string => $split->categoryCode
                        . '@' . ($split->countryCode ?? 'RO'),
                    $splits,
                )),
            ],
        );

        return $transaction;
    }

    /**
     * @param  list<TransactionSplitInput>  $splits
     */
    private function assertSplitsMatchTotal(int $amountMinor, array $splits): void
    {
        if ($splits === []) {
            return;
        }

        $total = 0;

        foreach ($splits as $split) {
            $total += $split->amountMinor;
        }

        if ($total !== $amountMinor) {
            throw TransactionSplitMismatchException::forTotals($amountMinor, $total);
        }
    }
}
