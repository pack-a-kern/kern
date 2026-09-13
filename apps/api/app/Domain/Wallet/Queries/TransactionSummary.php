<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Queries;

/**
 * One transaction as a screen shows it.
 *
 * `description` and `counterparty` are decrypted for display and nothing else — both are encrypted
 * at rest under the subject's own DEK, and the counterparty in particular is personal data about
 * someone who never used this application.
 *
 * `classificationStatus` is what makes a transaction tax-relevant rather than mere bookkeeping: it
 * records whether this money has been matched to something that explains it — a rent payment due, a
 * deposit's interest — or is still unexplained.
 */
final readonly class TransactionSummary
{
    public function __construct(
        public string $id,
        public string $accountId,
        public string $accountName,
        public string $bookedAt,
        public ?string $valueAt,
        /** Signed: negative is money out. */
        public int $amountMinor,
        public string $currencyCode,
        public string $description,
        public ?string $counterparty,
        public string $classificationStatus,
        /**
         * What this transaction was said to be, one entry per meaning.
         *
         * Carried on the row rather than fetched per transaction: the list *is* the classification
         * worklist, so every row needs its answer visible, and a request per row to show a pill is
         * the shape that makes a screen unusable at fifty rows.
         *
         * @var list<array{amount_minor: int, category_code: string, country_code: string|null}>
         */
        public array $splits,
        public string $source,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'account_name' => $this->accountName,
            'booked_at' => $this->bookedAt,
            'value_at' => $this->valueAt,
            'amount_minor' => $this->amountMinor,
            'currency_code' => $this->currencyCode,
            'description' => $this->description,
            'counterparty' => $this->counterparty,
            'classification_status' => $this->classificationStatus,
            'splits' => $this->splits,
            'source' => $this->source,
        ];
    }
}
