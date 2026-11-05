<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\DataTransferObjects;

/** What one import of the bank list did. */
final readonly class BankImportSummary
{
    public function __construct(
        /** Institutions in the ECB file for the configured countries and categories. */
        public int $listed,
        public int $created,
        /** Banks that were active and are no longer in the list. */
        public int $deactivated,
        /** Listed banks GLEIF maps no BIC to, so they have no 4-letter code. */
        public int $withoutBic,
        public string $listDate,
        public string $bicListDate,
        /** Banks GLEIF names a group for — BCR's is Erste Group — and banks it could not be asked about. */
        public int $grouped,
        public int $groupsFailed,
    ) {
    }
}
