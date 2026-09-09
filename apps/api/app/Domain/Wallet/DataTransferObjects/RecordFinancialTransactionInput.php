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
    ) {
    }
}
