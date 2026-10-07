<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\DataTransferObjects;

use DateTimeImmutable;

/**
 * One piece of categorised income, as the engine needs to see it.
 *
 * Deliberately not a transaction and not a split. It carries what building a `TaxFact` requires — how much,
 * in what currency, called what, on what date, evidenced by which row — and nothing about how the ledger
 * happens to store it. That is what lets `TaxEngine` read the ledger without importing `Wallet`'s models,
 * which is the boundary ADR 0001 draws and deptrac enforces.
 */
final readonly class LedgerIncomeEntry
{
    public function __construct(
        public string $sourceId,
        public string $categoryCode,
        public int $amountMinor,
        public string $currencyCode,
        public DateTimeImmutable $bookedAt,
    ) {
    }
}
