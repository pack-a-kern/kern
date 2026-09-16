<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Queries;

use App\Domain\StatementImport\Models\StatementImportRow;

/**
 * One staged row as the review screen reads it — decrypted for its owner, like every other screen
 * that shows a person their own statement.
 *
 * `duplicateTransactionId` is the row the ledger already holds. Shown rather than hidden: "this is
 * already here" is information a person acts on, and silently dropping the row would leave them
 * counting a statement that does not add up.
 */
final readonly class StatementRowSummary
{
    /** @param list<string> $tags */
    public function __construct(
        public string $id,
        public int $rowNumber,
        public ?string $bookedAt,
        public ?int $amountMinor,
        public ?string $currencyCode,
        public string $description,
        public ?string $counterparty,
        public ?string $reference,
        public ?string $duplicateTransactionId,
        public ?string $categoryCode,
        public ?string $countryCode,
        public array $tags,
        public ?string $proposalSource,
        public bool $included,
        public ?string $transactionId,
    ) {
    }

    public static function of(StatementImportRow $row): self
    {
        return new self(
            id: $row->id,
            rowNumber: $row->row_number,
            bookedAt: $row->booked_at?->toDateString(),
            amountMinor: $row->amount_minor === null ? null : (int) $row->amount_minor,
            currencyCode: $row->currency_code,
            description: (string) ($row->description_ciphertext ?? ''),
            counterparty: $row->counterparty_ciphertext === null ? null : (string) $row->counterparty_ciphertext,
            reference: $row->reference_ciphertext === null ? null : (string) $row->reference_ciphertext,
            duplicateTransactionId: $row->duplicate_transaction_id,
            categoryCode: $row->category_code,
            countryCode: $row->country_code,
            tags: $row->tagNames(),
            proposalSource: $row->proposal_source?->value,
            included: $row->included,
            transactionId: $row->transaction_id,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->rowNumber,
            'booked_at' => $this->bookedAt,
            'amount_minor' => $this->amountMinor,
            'currency_code' => $this->currencyCode,
            'description' => $this->description,
            'counterparty' => $this->counterparty,
            'reference' => $this->reference,
            'duplicate_transaction_id' => $this->duplicateTransactionId,
            'category_code' => $this->categoryCode,
            'country_code' => $this->countryCode,
            'tags' => $this->tags,
            'proposal_source' => $this->proposalSource,
            'included' => $this->included,
            'transaction_id' => $this->transactionId,
        ];
    }
}
