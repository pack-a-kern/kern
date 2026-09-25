<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Models;

use App\Domain\StatementImport\Enums\MappingSource;
use App\Domain\StatementImport\Enums\StatementFormat;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Exceptions\ImportNotOpenException;
use App\Support\Crypto\Casts\Encrypted;
use App\Support\Crypto\Concerns\FillsSubjectForeignKeyFirst;
use App\Support\Crypto\Concerns\HasUuidPrimaryKey;
use App\Support\Crypto\Contracts\EncryptsWithSubjectDek;
use App\Support\Tenancy\ScopedToTenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
 */
final class StatementImport extends Model implements EncryptsWithSubjectDek
{
    use FillsSubjectForeignKeyFirst;
    use HasUuidPrimaryKey;
    use ScopedToTenant;

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
    ];

    /** @var array<string, string> */
    protected $casts = [
        'format' => StatementFormat::class,
        'status' => StatementImportStatus::class,
        'mapping_source' => MappingSource::class,
        'preview_ciphertext' => Encrypted::class,
        'column_mapping' => 'array',
        'imported_at' => 'datetime',
    ];

    /**
     * Failures that reading the file again can cure: the converter or the reader was down, or the
     * reading broke part-way. A file that was refused for what it is — a scan, a password — is not
     * among them, because it would be refused again.
     */
    private const array FAILURES_WORTH_RETRYING = ['CONVERSION_FAILED', 'CONVERSION_EMPTY', 'READER_UNAVAILABLE', 'READ_FAILED'];

    public function encryptionSubjectId(): string
    {
        return $this->tax_subject_id;
    }

    /** Whether reading the stored file again makes sense: open, or failed for a passing reason, with nothing written. */
    public function canReadAgain(): bool
    {
        return $this->status->isOpen()
            || ($this->status === StatementImportStatus::FAILED
                && $this->imported_count === 0
                && in_array($this->failure_reason, self::FAILURES_WORTH_RETRYING, true));
    }

    /**
     * The columns, sample rows and labels the mapping screen draws, as they were read.
     *
     * The labels are what makes the screen answerable for a statement whose fields live on detail
     * lines: a person picks `Tranzactie la` from what the file actually contains, rather than typing
     * it and finding out it read nothing.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<list<string>>, labels: list<array<string, mixed>>, ai_answer: array<string, mixed>|null, classification: array<string, mixed>|null, conversion: array<string, mixed>|null, bank_source: string|null}
     */
    public function preview(): array
    {
        $decoded = json_decode(is_string($this->preview_ciphertext) ? $this->preview_ciphertext : '', true);

        if (! is_array($decoded)) {
            return ['columns' => [], 'rows' => [], 'labels' => [], 'ai_answer' => null, 'classification' => null, 'conversion' => null, 'bank_source' => null];
        }

        /** @var array{columns: list<array<string, mixed>>, rows: list<list<string>>, labels: list<array<string, mixed>>, ai_answer: array<string, mixed>|null, classification: array<string, mixed>|null, conversion: array<string, mixed>|null, bank_source: string|null} $preview */
        $preview = [
            'columns' => is_array($decoded['columns'] ?? null) ? array_values($decoded['columns']) : [],
            'rows' => is_array($decoded['rows'] ?? null) ? array_values($decoded['rows']) : [],
            'labels' => is_array($decoded['labels'] ?? null) ? array_values($decoded['labels']) : [],
            // What the model replied when it was asked how this file is built. Structure only.
            'ai_answer' => is_array($decoded['ai_answer'] ?? null) ? $decoded['ai_answer'] : null,
            // The counterparty calls: how many to expect, and for each, what was sent and replied.
            'classification' => is_array($decoded['classification'] ?? null) ? $decoded['classification'] : null,
            // How a PDF became the table, when the provider converted it. Null when read here.
            'conversion' => is_array($decoded['conversion'] ?? null) ? $decoded['conversion'] : null,
            // IBAN or AI when the bank was guessed; null when a person chose it, or nothing was found.
            'bank_source' => is_string($decoded['bank_source'] ?? null) ? $decoded['bank_source'] : null,
        ];

        return $preview;
    }

    /**
     * Counterparty calls are about to be worked out, and nothing about them is known yet.
     *
     * Recorded the moment the rows are staged, before the job that plans the calls has run. Without
     * it the screen would still hold the previous round — finished — and show a review that is about
     * to be rewritten under the person reading it.
     */
    public function queueClassification(): void
    {
        $this->changePreview(static function (array $preview): array {
            $preview['classification'] = [
                'run' => null,
                'expected' => null,
                'counterparties' => null,
                'failure' => null,
                'batches' => [],
            ];

            return $preview;
        });
    }

    /**
     * A new round of counterparty calls, replacing any earlier one.
     *
     * Kept beside the preview rather than in columns of its own: it is the same kind of thing — what
     * the model was shown and what it said — encrypted for the same reason, and read by the same
     * screen.
     */
    public function startClassification(string $run, int $expected, int $counterparties = 0, ?string $failure = null): void
    {
        $this->changePreview(static function (array $preview) use ($run, $expected, $counterparties, $failure): array {
            $preview['classification'] = [
                'run' => $run,
                'expected' => $expected,
                'counterparties' => $counterparties,
                'failure' => $failure,
                'batches' => [],
            ];

            return $preview;
        });
    }

    /**
     * One finished call, if it belongs to the current round.
     *
     * @param  array{sent: array<string, mixed>|null, reply: array<string, mixed>|null, failure: string|null}  $batch
     */
    public function recordClassificationBatch(string $run, array $batch): void
    {
        $this->changePreview(static function (array $preview) use ($run, $batch): array {
            $classification = is_array($preview['classification'] ?? null) ? $preview['classification'] : null;

            if ($classification === null || ($classification['run'] ?? null) !== $run) {
                return $preview;
            }

            $batches = is_array($classification['batches'] ?? null) ? array_values($classification['batches']) : [];
            $batches[] = $batch;
            $classification['batches'] = $batches;
            $preview['classification'] = $classification;

            return $preview;
        });
    }

    /**
     * Whether a round of model calls has just finished — every call it planned has come back.
     *
     * Only a round that made calls: one that had nothing to ask, or could not ask at all, ends in
     * the same moment a person pressed Continue, while they are still on the page.
     */
    public function classificationFinished(): bool
    {
        $classification = $this->preview()['classification'];
        $expected = $classification['expected'] ?? null;
        $batches = $classification['batches'] ?? [];

        return is_int($expected) && $expected > 0 && is_array($batches) && count($batches) >= $expected;
    }

    /** Whether a batch made for this round should still write anything. */
    public function isClassificationRun(string $run): bool
    {
        $classification = $this->preview()['classification'];

        return $classification !== null && ($classification['run'] ?? null) === $run;
    }

    /**
     * Rewrites the stored preview under a row lock.
     *
     * Locked because two writers exist — the round starting and a batch finishing — and a read-modify
     * -write without it would let one overwrite the other.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function changePreview(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            $fresh = self::withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($this->id);
            $decoded = json_decode(is_string($fresh->preview_ciphertext) ? $fresh->preview_ciphertext : '', true);

            /** @var array<string, mixed> $current */
            $current = is_array($decoded) ? $decoded : [];

            $fresh->preview_ciphertext = json_encode($change($current), JSON_THROW_ON_ERROR);
            $fresh->save();

            $this->preview_ciphertext = $fresh->preview_ciphertext;
        });
    }

    /**
     * The bank a person chose on the mapping screen — or none.
     *
     * Once a person has answered, the answer is theirs: the "found from the IBAN" note beside it no
     * longer describes it, so it goes.
     */
    public function chooseBank(?string $bankId): void
    {
        if (! $this->status->isOpen()) {
            throw ImportNotOpenException::forImport($this->id);
        }

        if ($bankId === $this->bank_id) {
            return;
        }

        $this->bank_id = $bankId;
        $this->save();

        $this->changePreview(static function (array $preview): array {
            $preview['bank_source'] = null;

            return $preview;
        });
    }

    /** @return HasMany<StatementImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(StatementImportRow::class, 'import_id')->orderBy('row_number');
    }
}
