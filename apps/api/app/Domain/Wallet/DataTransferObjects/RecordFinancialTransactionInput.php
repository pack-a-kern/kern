<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use App\Domain\Wallet\Enums\RecordSource;

/**
 * @param list<TransactionSplitInput> $splits empty = not yet classified
 *                                              (`App\Domain\Wallet\Enums\TransactionClassificationStatus::UNCLASSIFIED`).
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
        public ?string $valueAt = null,
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
