<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Queries;

/**
 * One transaction as a screen reads it: the figures in clear, the decrypted description and
 * counterparty, what it was taxed as (`splits`), where it happened (`bank`), and what it was for
 * (`tags`).
 *
 * The bank arrives as a name rather than an id alone because the list shows it, and asking the banks
 * table once per row is the N+1 a list cannot afford — {@see WalletQuery::summarise()} resolves it
 * for the whole page in one query.
 */
final readonly class TransactionSummary
{
    /**
     * @param  list<array{amount_minor: int, category_code: string, country_code: string|null}>  $splits
     * @param  list<array{id: string, name: string}>  $tags
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $accountName,
        public string $accountKind,
        public string $bookedAt,
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public ?string $counterparty,
        public string $classificationStatus,
        public array $splits,
        public string $source,
        public ?string $documentId = null,
        public ?string $bankId = null,
        public ?string $bankName = null,
        public array $tags = [],
        public ?string $importId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'account_name' => $this->accountName,
            'account_kind' => $this->accountKind,
            'booked_at' => $this->bookedAt,
            'amount_minor' => $this->amountMinor,
            'currency_code' => $this->currencyCode,
            'description' => $this->description,
            'counterparty' => $this->counterparty,
            'classification_status' => $this->classificationStatus,
            'splits' => $this->splits,
            'source' => $this->source,
            'document_id' => $this->documentId,
            'bank_id' => $this->bankId,
            'bank_name' => $this->bankName,
            'tags' => $this->tags,
            'import_id' => $this->importId,
        ];
    }
}
