<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Jobs;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\StatementImport\DataTransferObjects\ConvertedStatement;
use App\Domain\StatementImport\Enums\ImportNoticeKind;
use App\Domain\StatementImport\Enums\MappingSource;
use App\Domain\StatementImport\Enums\StatementFormat;
use App\Domain\StatementImport\Enums\StatementImportStatus;
use App\Domain\StatementImport\Events\StatementImportChanged;
use App\Domain\StatementImport\Exceptions\StatementParseUnavailableException;
use App\Domain\StatementImport\Exceptions\StatementRejectedException;
use App\Domain\StatementImport\Gateways\StatementParseGateway;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Services\GuessStatementBank;
use App\Domain\StatementImport\Services\NotifyImportOwner;
use App\Domain\StatementImport\Services\StatementConverter;
use App\Domain\StatementImport\Services\StatementFileContents;
use App\Domain\StatementImport\Services\SuggestColumnMapping;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Domain\Wallet\Enums\WalletAuditEvent;
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
     * Every call this job may make, end to end, plus a margin.
     *
     * The reader twice — the check before a PDF is converted, then the reading itself — the
     * conversion, and the model asked how the file is built. On the `default` queue it had sixty
     * seconds for all of it, which a thinking model alone can exceed.
     */
    public int $timeout;

    public function __construct(private readonly string $importId)
    {
        $this->tries = config()->integer('statement_import.scan_wait.checks') + count($this->backoff());
        $this->timeout = 2 * config()->integer('statement_import.parser.timeout_seconds')
            + config()->integer('ai.statements.conversion.timeout_seconds')
            + config()->integer('ai.statements.timeout_seconds')
            + config()->integer('ai.providers.job_timeout_margin_seconds');
        $this->onQueue(config()->string('ai.providers.queue'));
    }

    public function handle(
        StatementFileContents $files,
        StatementParseGateway $parser,
        StatementConverter $converter,
        SuggestColumnMapping $suggest,
        GuessStatementBank $banks,
        AuditChainService $audit,
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

        // Converted on an earlier reading: read what that reading read, and say how it was made.
        $contents = $files->converted($document);
        $conversion = $contents === null ? null : $import->preview()['conversion'];

        try {
            if ($contents === null) {
                $contents = $files->original($document);

                if ($contents === null) {
                    $this->fail($import, 'FILE_MISSING');

                    return;
                }

                /*
                 * A PDF goes to the provider to become CSV, where it can and may.
                 *
                 * Checked here first, so a scan, a password or too many pages is refused exactly as
                 * it always was — the converter would read a scan, and a statement import does not
                 * take one. Anything else is read here as it always was.
                 */
                if ($converter->isAvailable($import->tax_subject_id) && $parser->check($contents) === StatementFormat::PDF) {
                    $converted = $converter->convert($contents);
                    $this->recordSend($audit, $import, $converted);

                    if ($converted->csv === null) {
                        // Stopped, not read another way: which reader made the rows is part of
                        // what a person is confirming, and a quiet fallback would hide it.
                        $this->fail($import, $converted->failure ?? ConvertedStatement::FAILED);

                        return;
                    }

                    $files->storeConverted($document, $converted->csv);
                    $contents = $converted->csv;
                    $conversion = $converted->report();
                }
            }

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
        /*
         * Which bank issued it: from the statement's own IBAN if the heading has one, and only
         * otherwise from the model, which is then shown the heading to answer from. A bank a person
         * already chose is theirs and is not guessed over.
         */
        $bank = $import->bank_id === null ? $banks->fromIban($parsed->preamble) : null;
        $bankSource = $bank === null ? null : 'IBAN';
        $suggestion = $suggest($import->tax_subject_id, $parsed, askBank: $import->bank_id === null && $bank === null);

        if ($import->bank_id === null && $bank === null) {
            $bank = $banks->fromAnswer($suggestion->raw['bank'] ?? null);
            $bankSource = $bank === null ? null : 'AI';
        }

        // The format of what was uploaded: a converted PDF is read as CSV, but it is still a PDF.
        $import->format = $conversion === null ? $parsed->format : StatementFormat::PDF;

        if ($bank !== null) {
            $import->bank_id = $bank->id;
        }

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
            // How the PDF became the table below, when it was converted: the converter's own
            // pipeline, time and warning — null when the file was read here.
            'conversion' => $conversion,
            // Where the bank came from, so the screen can say "found from the IBAN" or "suggested
            // by the AI" beside it — or keep what an earlier reading said, when nothing was guessed.
            'bank_source' => $bankSource ?? $import->preview()['bank_source'],
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
        app(NotifyImportOwner::class)($import, ImportNoticeKind::READY_FOR_MAPPING);
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

    /** The whole file left the installation — recorded whatever came back. */
    private function recordSend(AuditChainService $audit, StatementImport $import, ConvertedStatement $converted): void
    {
        $audit->record(AuditEventInput::occurringNow(
            subjectId: $import->tax_subject_id,
            actorId: $import->created_by,
            eventType: WalletAuditEvent::STATEMENT_SENT_FOR_CONVERSION->value,
            resourceType: WalletAuditEvent::STATEMENT_SENT_FOR_CONVERSION->resourceType(),
            resourceId: $import->id,
            // Ids and the outcome. The converter's warning stays out: it can quote the file.
            payloadHash: $audit->hashPayload([
                'document_id' => $import->document_id,
                'failure' => $converted->failure,
                'pipeline' => $converted->pipeline,
                'ms' => $converted->milliseconds,
            ]),
        ));
    }

    private function fail(StatementImport $import, string $reason): void
    {
        $import->status = StatementImportStatus::FAILED;
        // A closed code, never the reader's sentence and never a line of the file.
        $import->failure_reason = $reason;
        $import->save();

        StatementImportChanged::dispatch($import->id, $import->tax_subject_id, $import->status);
        app(NotifyImportOwner::class)($import, ImportNoticeKind::FAILED);
    }
}
