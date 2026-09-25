<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Actions;

use Carbon\CarbonImmutable;
use Kern\TaxRomania\Wallet\Enums\WalletAuditEvent;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Services\LookalikeTransactions;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * Records that a set of lookalike transactions are genuinely separate payments.
 *
 * Two 12-lei coffees at the same shop on the same day look exactly like one coffee imported twice,
 * and no rule can tell them apart — only the person who drank them. So they are asked once, and the
 * answer is kept, or the same pair is raised again every time the screen opens.
 *
 * Every row is marked, not the set. A set has no identity of its own, and a third identical payment
 * arriving later is a new question: its row is unmarked, so the group reappears with it.
 */
final readonly class KeepDuplicateTransactions
{
    public function __construct(private WalletAudit $audit)
    {
    }

    /**
     * @param  list<string>  $transactionIds
     */
    public function __invoke(array $transactionIds, ?string $actorId = null): void
    {
        $transactions = LookalikeTransactions::load($transactionIds);
        $ids = array_map(LookalikeTransactions::id(...), $transactions);

        FinancialTransaction::query()
            ->whereKey($ids)
            ->update(['duplicate_reviewed_at' => CarbonImmutable::now('UTC')]);

        foreach ($transactions as $transaction) {
            $this->audit->record(
                subjectId: $transaction->tax_subject_id,
                actorId: $actorId,
                eventType: WalletAuditEvent::DUPLICATES_KEPT->value,
                resourceType: WalletAuditEvent::DUPLICATES_KEPT->resourceType(),
                resourceId: LookalikeTransactions::id($transaction),
                payload: ['group_size' => count($transactions)],
            );
        }
    }
}
