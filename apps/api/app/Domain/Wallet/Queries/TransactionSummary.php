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
        /**
         * The source's kind, so a screen can name it. The general source has no stored name — the
         * screen supplies its label — and without the kind every row in it showed an empty column.
         */
        public string $accountKind,
        public string $bookedAt,
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
        /**
         * The document this transaction was built from, if any. A merge keeps the document-backed
         * row, so the duplicates view needs to show which one that is.
         */
        public ?string $documentId = null,
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
        ];
    }
}
