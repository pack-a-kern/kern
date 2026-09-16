<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Models;

use App\Domain\StatementImport\Enums\MappingSource;
use App\Domain\StatementImport\Enums\StatementFormat;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\Subjects\Tenancy\ScopedToTaxSubject;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `statement_imports` — one bank statement on its way from a file to transactions.
 *
 * `preview_ciphertext` is the one encrypted column: the columns and first rows the mapping screen
 * shows are the statement itself. Everything else here is state and counts.
 *
 * @property string $id
 * @property string $tax_subject_id
 * @property string $account_id
 * @property string|null $bank_id
 * @property string|null $document_id
 * @property StatementFormat|null $format
 * @property string|null $sheet
 * @property StatementImportStatus $status
 * @property string|null $failure_reason
 * @property string|null $preview_ciphertext
 * @property array<string, mixed>|null $column_mapping
 * @property MappingSource|null $mapping_source
 * @property int $row_count
 * @property int $duplicate_count
 * @property int $excluded_count
 * @property int $imported_count
 * @property int $lines_read
 * @property int $lines_folded
 * @property int $lines_skipped
 * @property string|null $ai_failure_reason
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon|null $imported_at
 * @property \Illuminate\Support\Carbon|null $undone_at
 */
final class StatementImport extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTaxSubject;

    protected $table = 'statement_imports';

    /** @var list<string> */
    protected $fillable = [
        'tax_subject_id',
        'account_id',
        'bank_id',
        'document_id',
        'format',
        'sheet',
        'status',
        'failure_reason',
        'preview_ciphertext',
        'column_mapping',
        'mapping_source',
        'row_count',
        'duplicate_count',
        'excluded_count',
        'imported_count',
        'lines_read',
        'lines_folded',
        'lines_skipped',
        'ai_failure_reason',
        'created_by',
        'imported_at',
        'undone_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'format' => StatementFormat::class,
        'status' => StatementImportStatus::class,
        'mapping_source' => MappingSource::class,
        'preview_ciphertext' => Encrypted::class,
        'column_mapping' => 'array',
        'imported_at' => 'datetime',
        'undone_at' => 'datetime',
    ];

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }

    /**
     * The columns, sample rows and labels the mapping screen draws, as they were read.
     *
     * The labels are what makes the screen answerable for a statement whose fields live on detail
     * lines: a person picks `Tranzactie la` from what the file actually contains, rather than typing
     * it and finding out it read nothing.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<list<string>>, labels: list<array<string, mixed>>, ai_answer: array<string, mixed>|null}
     */
    public function preview(): array
    {
        $decoded = json_decode(is_string($this->preview_ciphertext) ? $this->preview_ciphertext : '', true);

        if (! is_array($decoded)) {
            return ['columns' => [], 'rows' => [], 'labels' => [], 'ai_answer' => null];
        }

        /** @var array{columns: list<array<string, mixed>>, rows: list<list<string>>, labels: list<array<string, mixed>>, ai_answer: array<string, mixed>|null} $preview */
        $preview = [
            'columns' => is_array($decoded['columns'] ?? null) ? array_values($decoded['columns']) : [],
            'rows' => is_array($decoded['rows'] ?? null) ? array_values($decoded['rows']) : [],
            'labels' => is_array($decoded['labels'] ?? null) ? array_values($decoded['labels']) : [],
            // What the model replied when it was asked how this file is built. Structure only.
            'ai_answer' => is_array($decoded['ai_answer'] ?? null) ? $decoded['ai_answer'] : null,
        ];

        return $preview;
    }

    /** @return HasMany<StatementImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(StatementImportRow::class, 'import_id')->orderBy('row_number');
    }
}
