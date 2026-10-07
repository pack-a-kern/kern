<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Contracts;

use Kern\TaxRomania\TaxEngine\DataTransferObjects\LedgerIncomeEntry;

/**
 * Where a year's categorised income comes from.
 *
 * A port, for the reason ADR 0010 gives for the two beside it: the engine states what it needs and the module
 * that owns the data implements it, so the dependency points inward. The first attempt at this read
 * `TransactionSplit` and `FinancialTransaction` directly from inside `TaxEngine` and deptrac refused it —
 * correctly, and after a comment had already been written acknowledging the boundary it was crossing.
 *
 * `$categoryCodes` is passed in rather than decided here: which categories count as income is a question about
 * the vocabulary in `config/reference/tax_categories.php`, and an adapter that answered it would be a second
 * place for that answer to live.
 */
interface LedgerIncomeProvider
{
    /**
     * @param  list<string>  $categoryCodes
     * @return list<LedgerIncomeEntry>
     */
    public function incomeIn(string $taxSubjectId, int $fiscalYear, array $categoryCodes): array;
}
