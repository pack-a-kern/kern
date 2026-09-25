<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

/**
 * What a merge left behind, for the caller to finish with.
 *
 * The documents are reported rather than merged here: `Wallet` may not depend on `Documents`
 * (`deptrac.php`), so combining the evidence behind the merged rows is the caller's half of the job.
 */
final readonly class MergedTransactions
{
    /**
     * @param  list<string>  $absorbedTransactionIds  the rows that were deleted into the survivor
     * @param  list<string>  $absorbedDocumentIds  documents behind those rows, other than the survivor's own
     */
    public function __construct(
        public string $survivorId,
        public ?string $survivorDocumentId,
        public array $absorbedTransactionIds,
        public array $absorbedDocumentIds,
    ) {
    }
}
