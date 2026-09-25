<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\DataTransferObjects;

use Kern\TaxRomania\Wallet\Enums\RecordSource;

/**
 * @param list<TransactionSplitInput> $splits empty = not yet classified
 *                                              (`Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus::UNCLASSIFIED`).
 */
final readonly class RecordFinancialTransactionInput
{
    /**
     * @param list<TransactionSplitInput> $splits
     */
    public function __construct(
        public string $taxSubjectId,
        public string $accountId,
        public string $bookedAt,
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public string $counterparty,
        /** The bank this happened at, where it is known — a label, never a second account. */
        public ?string $bankId = null,
        /** @var list<string> tag ids, already resolved from names by `TagDirectory` */
        public array $tagIds = [],
        public ?string $externalId = null,
        public array $splits = [],
        public RecordSource $source = RecordSource::MANUAL,
        /** Who recorded it, for the audit chain. Null for an importer or a document parser. */
        public ?string $actorId = null,
        /**
         * The document this came from, and which reading of it was trusted.
         *
         * Null for a transaction typed in with no paper behind it, which stays perfectly
         * legitimate — `source` is what says which kind this is.
         */
        public ?string $documentId = null,
        public ?string $documentExtractionId = null,
    ) {
    }
}
