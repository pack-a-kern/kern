<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\MergedTransactions;
use App\Domain\Wallet\DataTransferObjects\TransactionSplitInput;
use App\Domain\Wallet\Enums\TransactionClassificationStatus;
use App\Domain\Wallet\Enums\WalletAuditEvent;
use App\Domain\Wallet\Exceptions\NotDuplicateTransactionsException;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use App\Domain\Wallet\Services\DuplicateSurvivor;
use App\Domain\Wallet\Services\LookalikeTransactions;
use App\Domain\Wallet\Services\WalletAudit;
use Illuminate\Support\Facades\DB;

/**
 * Combines lookalike transactions into one.
 *
 * Imports are never refused for resembling something already recorded — a person merges afterwards,
 * with the rows side by side. So this is where a duplicate actually stops being counted twice.
 *
 * ## Nothing the discarded rows knew is lost
 *
 * - **Which row survives** is {@see DuplicateSurvivor}'s decision: the document-backed one, because
 *   it carries the evidence.
 * - **A classification** moves to the survivor when the survivor has none. A receipt imported
 *   unclassified and a statement row a person already filed as `RENT` are one payment, and merging
 *   them must not return it to the worklist.
 * - **Interest events** that named a discarded row as their settlement are pointed at the survivor
 *   rather than left pointing at nothing.
 * - **Documents** behind the discarded rows are reported back, for the caller to fold into the
 *   survivor's document as further pages — see {@see MergedTransactions} for why not here.
 *
 * Each discarded row goes through {@see DeleteFinancialTransaction}, so it leaves the same audit
 * entry any deletion does, and the merge itself is recorded on the survivor.
 */
final readonly class MergeDuplicateTransactions
{
    public function __construct(
        private ClassifyTransaction $classify,
        private DeleteFinancialTransaction $delete,
        private WalletAudit $audit,
    ) {
    }

    /**
     * @param  list<string>  $transactionIds
     */
    public function __invoke(array $transactionIds, ?string $actorId = null): MergedTransactions
    {
        $transactions = LookalikeTransactions::load($transactionIds);

        /*
         * Refused here as well as never offered by the view, because this is the step that deletes.
         * A bank lists each movement on its own line, so two lines of one statement are two
         * payments — merging them removes a real one. Keeping is not checked: it deletes nothing.
         */
        $imports = [];

        foreach ($transactions as $transaction) {
            $import = $transaction->getAttribute('import_id');

            if (! is_string($import) || $import === '') {
                continue;
            }

            if (isset($imports[$import])) {
                throw NotDuplicateTransactionsException::sameStatement();
            }

            $imports[$import] = true;
        }

        $survivor = DuplicateSurvivor::pick($transactions);
        $survivorId = LookalikeTransactions::id($survivor);

        $losers = array_values(array_filter(
            $transactions,
            static fn (FinancialTransaction $transaction): bool => LookalikeTransactions::id($transaction) !== $survivorId,
        ));
        $loserIds = array_map(LookalikeTransactions::id(...), $losers);

        return DB::transaction(function () use ($survivor, $survivorId, $losers, $loserIds, $actorId): MergedTransactions {
            // Before the rows go: the reference is `nullOnDelete`, so re-pointing it afterwards would
            // find nothing to re-point, and a realised interest payment would be left with no record
            // of the money that settled it.
            DB::table('deposit_interest_events')
                ->whereIn('transaction_id', $loserIds)
                ->update(['transaction_id' => $survivorId]);

            $this->inheritClassification($survivor, $losers, $actorId);

            $documentIds = [];

            foreach ($losers as $loser) {
                if ($loser->document_id !== null && $loser->document_id !== $survivor->document_id) {
                    $documentIds[$loser->document_id] = true;
                }

                ($this->delete)($loser, $actorId);
            }

            $this->audit->record(
                subjectId: $survivor->tax_subject_id,
                actorId: $actorId,
                eventType: WalletAuditEvent::TRANSACTIONS_MERGED->value,
                resourceType: WalletAuditEvent::TRANSACTIONS_MERGED->resourceType(),
                resourceId: $survivorId,
                payload: [
                    // Ids only — the figures are on the survivor, and each deleted row already left
                    // its own entry with its amount and date.
                    'absorbed' => implode(',', $loserIds),
                ],
            );

            return new MergedTransactions(
                survivorId: $survivorId,
                survivorDocumentId: $survivor->document_id,
                absorbedTransactionIds: $loserIds,
                absorbedDocumentIds: array_keys($documentIds),
            );
        });
    }

    /**
     * The first classification among the discarded rows, when the survivor has none of its own.
     *
     * The survivor's own answer is never overwritten: it is the document-backed row, and a person
     * who classified it did so looking at the evidence. The amounts match by construction — every
     * row in a lookalike set has the same amount — so the splits partition the survivor exactly.
     *
     * @param  list<FinancialTransaction>  $losers
     */
    private function inheritClassification(FinancialTransaction $survivor, array $losers, ?string $actorId): void
    {
        if ($survivor->classification_status !== TransactionClassificationStatus::UNCLASSIFIED) {
            return;
        }

        foreach ($losers as $loser) {
            $splits = TransactionSplit::query()
                ->where('transaction_id', LookalikeTransactions::id($loser))
                ->get()
                ->map(static fn (TransactionSplit $split): TransactionSplitInput => new TransactionSplitInput(
                    amountMinor: $split->amount_minor,
                    categoryCode: $split->category_code,
                    countryCode: $split->country_code,
                ))
                ->all();

            if ($splits !== []) {
                ($this->classify)($survivor, array_values($splits), $actorId);

                return;
            }
        }
    }
}
