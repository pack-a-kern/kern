<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Queries;

use App\Domain\StatementImport\Models\StatementImport;

/**
 * One import as the history page reads it: what was imported, from where, and what became of it.
 *
 * No file name and no row content — the counts and the state are what the page is for, and the
 * statement's own text belongs to the rows and the document, both encrypted.
 *
 * `ai_failure_reason` is a closed code and never a provider's own words, for the same reason.
 */
final readonly class StatementImportSummary
{
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $bankId,
        public ?string $documentId,
        public ?string $format,
        public ?string $sheet,
        public string $status,
        public ?string $failureReason,
        public int $rowCount,
        public int $duplicateCount,
        public int $excludedCount,
        public int $importedCount,
        public int $linesRead,
        public int $linesFolded,
        public int $linesSkipped,
        public ?string $aiFailureReason,
        public ?string $importedAt,
        public ?string $undoneAt,
        public string $createdAt,
    ) {
    }

    public static function of(StatementImport $import): self
    {
        return new self(
            id: $import->id,
            accountId: $import->account_id,
            bankId: $import->bank_id,
            documentId: $import->document_id,
            format: $import->format?->value,
            sheet: $import->sheet,
            status: $import->status->value,
            failureReason: $import->failure_reason,
            rowCount: $import->row_count,
            duplicateCount: $import->duplicate_count,
            excludedCount: $import->excluded_count,
            importedCount: $import->imported_count,
            linesRead: $import->lines_read,
            linesFolded: $import->lines_folded,
            linesSkipped: $import->lines_skipped,
            aiFailureReason: $import->ai_failure_reason,
            importedAt: $import->imported_at?->toIso8601String(),
            undoneAt: $import->undone_at?->toIso8601String(),
            createdAt: $import->created_at?->toIso8601String() ?? '',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->accountId,
            'bank_id' => $this->bankId,
            'document_id' => $this->documentId,
            'format' => $this->format,
            'sheet' => $this->sheet,
            'status' => $this->status,
            'failure_reason' => $this->failureReason,
            'row_count' => $this->rowCount,
            'duplicate_count' => $this->duplicateCount,
            'excluded_count' => $this->excludedCount,
            'imported_count' => $this->importedCount,
            // The arithmetic behind the transaction count: a person seeing 12 522 lines become
            // 1 700 transactions can tell at a glance whether that is folding or loss.
            'lines_read' => $this->linesRead,
            'lines_folded' => $this->linesFolded,
            'lines_skipped' => $this->linesSkipped,
            'ai_failure_reason' => $this->aiFailureReason,
            'imported_at' => $this->importedAt,
            'undone_at' => $this->undoneAt,
            'created_at' => $this->createdAt,
        ];
    }
}
