<?php

declare(strict_types=1);

namespace App\Domain\Rental\DataTransferObjects;

/**
 * One row read from `financial_transactions` (`Domain/Wallet`'s table) via the plain query
 * builder — see `App\Domain\Rental\Services\Matching\TransactionMatchCandidateFinder`'s docblock
 * for why a raw, column-selecting query is used instead of importing
 * `App\Domain\Wallet\Models\FinancialTransaction` (`docs/AGENTS.md` §6). Carries only the plain,
 * unencrypted columns this module's matching heuristic needs — never the transaction's encrypted
 * description/counterparty, which this module has no way to decrypt and does not need to.
 */
final readonly class TransactionMatchCandidate
{
    public function __construct(
        public string $transactionId,
        public string $accountId,
        public string $bookedAt,
        public int $amountMinor,
        public string $currencyCode,
    ) {
    }
}
