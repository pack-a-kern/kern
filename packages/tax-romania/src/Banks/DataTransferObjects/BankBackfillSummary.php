<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\DataTransferObjects;

final readonly class BankBackfillSummary
{
    /** @param list<string> $missingMonths `YYYY-MM` months for which no archived list was found */
    public function __construct(
        /** Archived lists read. */
        public int $snapshots,
        /** Banks added — every one of them no longer in today's list, so inactive. */
        public int $created,
        /** Banks already known whose first appearance was moved back to an earlier list. */
        public int $datedBack,
        /** Added banks with no BIC of their own, so no 4-letter code for an IBAN to match. */
        public int $withoutBic,
        public array $missingMonths,
        public string $until,
    ) {
    }
}
