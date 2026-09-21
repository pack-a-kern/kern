<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\StatementImport\Enums\MappingSource;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Exceptions\StatementParseUnavailableException;
use App\Domain\StatementImport\Exceptions\StatementRejectedException;
use App\Domain\StatementImport\Gateways\StatementParseGateway;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Services\SuggestColumnMapping;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\Exceptions\ObjectNotFoundException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reads an uploaded statement into rows, once the file has been scanned.
 *
 * ## Why it waits rather than fails
 *
 * A freshly uploaded file sits in quarantine until ClamAV has looked at it, which is seconds usually
 * and minutes when the scanner is reloading its signatures. Failing on "no content yet" would turn an
 * ordinary wait into an error a person has to retry by hand, so the job releases itself back to the
 * queue and tries again — a bounded number of times, after which the import fails honestly.
 *
 * ## Two kinds of failure, kept apart
 *
 * A file that cannot be read — a scan with no text, a password, something that is not a statement —
 * ends the import with a reason a person can act on. A reader that could not be reached is the
 * installation's problem: the job throws, the queue retries it, and the import stays as it was.
 */
final class ReadStatementFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Every attempt this job may take: the antivirus checks, plus the reader's own retries.
     *
     * Two budgets in one number, because the queue has only one. They are sized separately — see
     * `statement_import.scan_wait.checks` and `statement_import.parser.retry_seconds` — so a slow scan
     * leaves the reader its retries.
     */
    public int $tries;

    /** Statuses whose bytes have been promoted out of quarantine into the real store. */
    private const array READABLE_STATUSES = [
        DocumentStatus::SCANNED,
        DocumentStatus::PROCESSING,
        DocumentStatus::READY,
    ];

    /**
     * The reader's time plus the model's, plus a margin.
     *
     * This job reads the file and then asks the model how it is built, so it needs both. On the
     * `default` queue it had sixty seconds for all of it, which a thinking model alone can exceed.
     */
    public int $timeout;

    public function __construct(private readonly string $importId)
    {
        $this->tries = config()->integer('statement_import.scan_wait.checks') + count($this->backoff());
        $this->timeout = config()->integer('statement_import.parser.timeout_seconds')
            + config()->integer('ai.statements.timeout_seconds')
            + config()->integer('ai.providers.job_timeout_margin_seconds');
        $this->onQueue(config()->string('ai.providers.queue'));
    }

    public function handle(
        EncryptedObjectStore $store,
        StatementParseGateway $parser,
        SuggestColumnMapping $suggest,
    ): void {
        // Without the tenant scope: a queue worker acts for no one, and the import's own row carries
        // the subject every read below is bounded by.
        $import = StatementImport::withoutGlobalScope(TaxSubjectScope::class)->find($this->importId);

        // Deleted while this waited: nothing to read, and failing would leave a failed job behind
        // for an import the person already abandoned.
        if ($import === null || $import->status !== StatementImportStatus::READING) {
            return;
        }

        $document = $import->document_id === null
            ? null
            : Document::withoutGlobalScope(TaxSubjectScope::class)->with('parts')->find($import->document_id);

        if ($document === null) {
            $this->fail($import, 'FILE_MISSING');

            return;
        }

        if ($document->status === DocumentStatus::REJECTED) {
            // The scanner refused the file. Its own reason is on the document; the import says only
            // that the file did not get through.
            $this->fail($import, 'FILE_REJECTED');

            return;
        }

        if (! in_array($document->status, self::READABLE_STATUSES, true)) {
            $this->release(config()->integer('statement_import.scan_wait.seconds_between_checks'));

            return;
        }

        $part = $document->firstPart();

        if ($part === null) {
            $this->fail($import, 'FILE_MISSING');

            return;
        }

        try {
            $stream = $store->get($part->objectKey());
            $contents = stream_get_contents($stream);
            fclose($stream);
        } catch (ObjectNotFoundException) {
            $this->fail($import, 'FILE_MISSING');

            return;
        }

        if ($contents === false) {
            $this->fail($import, 'FILE_MISSING');

            return;
        }

        try {
            $parsed = $parser->parse($contents, $import->sheet);
        } catch (StatementRejectedException $exception) {
            $this->fail($import, $exception->reason);

            return;
        } catch (StatementParseUnavailableException $exception) {
            // The reader, not the file. Thrown so the queue retries it after the next pause in
            // `backoff()`; when the retries run out, `failed()` says so on the import.
            throw $exception;
        }

        /*
         * A suggested form, where a model is available and the taxpayer has consented.
         *
         * The columns, the labels found beneath transactions, and the first lines of the file go
         * out — the lines because a model cannot tell a detail line from a heading without seeing
         * one. It is stored as the screen's starting point, never applied: a person confirms it
         * against a preview, which is where a balance column mistaken for an amount is obvious and
         * a ledger is where it is not.
         */
        $suggestion = $suggest($import->tax_subject_id, $parsed);

        $import->format = $parsed->format;
        $import->sheet = $parsed->sheet;
        $import->row_count = count($parsed->rows);
        $import->preview_ciphertext = json_encode([
            'sheets' => $parsed->sheets,
            'header_row' => $parsed->headerRow,
            'warnings' => $parsed->warnings,
            'columns' => array_map(
                static fn ($column): array => $column->toArray(),
                $parsed->columns,
            ),
            // The labels found beneath transactions, so the mapping screen can offer them to choose
            // from rather than ask a person to type `Tranzactie la` correctly.
            'labels' => array_map(
                static fn ($group): array => $group->toArray(),
                $parsed->labelGroups,
            ),
            // Structure only — column numbers, labels, separators — and encrypted with the rest of
            // the preview, because it describes a person's own statement.
            'ai_answer' => $suggestion->raw,
            // Only the first rows: the mapping screen shows a sample, and keeping the whole
            // statement here would be a second copy of the file in the database.
            'rows' => array_slice($parsed->rows, 0, config()->integer('statement_import.limits.preview_rows')),
        ], JSON_THROW_ON_ERROR);

        // Null when the model was unavailable or its answer did not describe this file, and the
        // screen then falls back to its own guess from the column shapes.
        if ($suggestion->form !== null) {
            $import->column_mapping = $suggestion->form->toArray();
            $import->mapping_source = MappingSource::AI;
        }

        // Kept even though the screen works without it: "the model ran out of room" and "nobody was
        // asked" both show as an empty form, and only one of them is a setting somebody can change.
        $import->ai_failure_reason = $suggestion->failure;

        $import->status = StatementImportStatus::MAPPING;
        $import->failure_reason = null;
        $import->save();

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);
    }

    /**
     * The pauses before each retry when the reader could not be reached.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $pauses */
        $pauses = config()->array('statement_import.parser.retry_seconds');

        return $pauses;
    }

    /**
     * The queue gave up on this job — the reader stayed unreachable, or something else broke.
     *
     * Without this the import stayed in READING for ever: the job was gone, nothing would ever move
     * it on, and the screen said "reading the file" to someone waiting for it.
     */
    public function failed(?Throwable $exception): void
    {
        $import = StatementImport::withoutGlobalScope(TaxSubjectScope::class)->find($this->importId);

        if ($import === null || $import->status !== StatementImportStatus::READING) {
            return;
        }

        $this->fail(
            $import,
            $exception instanceof StatementParseUnavailableException ? 'READER_UNAVAILABLE' : 'READ_FAILED',
        );
    }

    private function fail(StatementImport $import, string $reason): void
    {
        $import->status = StatementImportStatus::FAILED;
        // A closed code, never the reader's sentence and never a line of the file.
        $import->failure_reason = $reason;
        $import->save();

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);
    }
}
