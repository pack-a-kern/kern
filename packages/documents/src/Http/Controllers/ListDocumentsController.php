<?php

declare(strict_types=1);

namespace Kern\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Kern\Documents\Enums\DocumentStage;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Enums\TransactionImportState;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Documents\Parsing\ExtractionSchema;
use Kern\Documents\Queries\ResolveTransactionImportState;
use Kern\Platform\Tenancy\Tenancy;
use Kern\Platform\Tenancy\TenantContext;

/**
 * `GET /api/v1/subjects/{subject}/documents` — every document this subject owns, newest first.
 *
 * ## Why this exists
 *
 * Until this endpoint, the inbox listed documents out of the browser's own IndexedDB — the records
 * the *uploading device* happened to keep. That made the screen lie in two directions: a document
 * uploaded on a phone was invisible on a laptop, and clearing site data emptied the list while the
 * documents themselves sat safely in the object store, now unreachable through the UI. A list of
 * what a person owns has to come from the server that owns it; the local database goes back to
 * being an offline cache, which is what it is good at.
 *
 * ## The name is the presented one, not the uploaded one
 *
 * Rows carry `Document::presentedFilename()` — the name the person chose, or one derived from the
 * content hash if they never chose one. The name a file arrived with is never the label: a phone
 * calls every photograph `IMG_0042.jpg`, and a name chosen by whoever sent it can carry a CNP.
 *
 * ## The name is decrypted here, deliberately
 *
 * `original_filename_ciphertext` is encrypted at rest under the subject's own DEK, and this returns
 * it in clear to that same subject. That is not a hole — it is the point. Encryption at rest
 * protects against a stolen database, a leaked backup, an operator reading rows; it was never meant
 * to hide a person's own filenames from them, and a list of documents identified only by a UUID and
 * a MIME type is unusable. Every row is tenant-scoped
 * (`Kern\Platform\Tenancy\ScopedToTenant`), so the only reader is a user with an active
 * membership on this subject.
 *
 * There is deliberately **no filename search**. The column is encrypted with a random nonce, so it
 * is not searchable without a blind index, and a blind index on a filename would buy very little
 * (`BLUEPRINT.md` §4.4 reserves them for equality lookups that matter, like CNP). Filtering is by
 * the metadata that is stored in clear: status, type and the received date.
 */
final class ListDocumentsController
{
    /** Matches `ListDocumentExtractionsController`, so both document lists page identically. */
    private const int DEFAULT_PER_PAGE = 20;

    private const int MAX_PER_PAGE = 100;

    public function __invoke(
        Request $request,
        string $subject,
        TenantContext $tenant,
        ResolveTransactionImportState $importState,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(DocumentStatus::class)],
            'type' => ['sometimes', Rule::enum(DocumentType::class)],
            // Inclusive bounds on the calendar date, not on an instant: a person filtering "up to
            // 31 March" means the whole of that day.
            'received_from' => ['sometimes', 'date'],
            'received_to' => ['sometimes', 'date'],
            // Documents whose proposals nobody has looked at yet — the actual working queue, and
            // the one filter that is not simply a column.
            'needs_review' => ['sometimes', 'boolean'],
            // The stage a person sees, not the pipeline status — filtering has to speak the same
            // language the column does, or the list offers words that appear nowhere in it.
            'stage' => ['sometimes', 'string', Rule::enum(DocumentStage::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer'],
        ]);

        // Newest first, ordered by the primary key rather than by `received_at`.
        //
        // `id` is a UUIDv7 (`Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey`), whose leading 48
        // bits are a millisecond timestamp — so descending id *is* newest-arrival-first, with no
        // ties to break and no second ordering column needed.
        //
        // Ordering on `received_at` looked more honest and was quietly broken. `cursorPaginate`
        // encodes the ordering columns into the cursor, and a timestamp serialises into it as
        // `2026-04-01T08:00:00+00:00` while sqlite stores `2026-04-01 08:00:00`. Compared as text —
        // which is what sqlite does with a date column — every stored row sorts below the cursor
        // value, so the "give me what comes after this" clause matched everything and each page
        // returned the first page again. Postgres would have compared them as timestamps and been
        // fine, which is the worst version of this bug: correct in production, silently wrong in
        // the suite that is supposed to catch it. A string primary key compares identically on both
        // engines.
        //
        // The one behavioural difference is worth stating: this orders by when a document *arrived*
        // rather than by a `received_at` an importer might backdate. For an inbox that is the more
        // useful order, and the date filter below still reads `received_at`.
        // Parts eager-loaded: every row shows a type and a total size, both of which come from
        // them, and asking per row is the N+1 a list screen cannot afford.
        $query = Document::query()->with('parts')->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('type', (string) $validated['type']);
        }

        if (isset($validated['received_from'])) {
            $query->whereDate('received_at', '>=', (string) $validated['received_from']);
        }

        if (isset($validated['received_to'])) {
            $query->whereDate('received_at', '<=', (string) $validated['received_to']);
        }

        if (isset($validated['stage']) && is_string($validated['stage'])) {
            $this->applyStage($query, DocumentStage::from($validated['stage']), $tenant->currentTenantId());
        }

        if ($request->boolean('needs_review')) {
            // "Still needs your attention", not "has a machine-made proposal waiting".
            //
            // The first version meant the latter, and it contradicted the screen: a document showed
            // a *Review* button — because it is `READY` and can be opened — while the filter said
            // nothing needed reviewing, because no parser had proposed anything for it. Both were
            // true and together they were nonsense.
            //
            // It spans two stages now: a reading waiting to be accepted, and one accepted but not
            // yet imported. Both are outstanding, which is all this flag ever claimed to mean — the
            // stages are the finer question, and they are what the screen drives.
            $subjectId = $tenant->currentTenantId();

            $query->where('status', DocumentStatus::READY->value)
                ->whereNotIn('id', self::documentsThatProducedRecords($subjectId));
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE)));

        // Numbered pages, which means offset pagination and the `count(*)` that comes with it.
        //
        // A cursor cannot answer "how many are there" or "take me to page 7", and both are things a
        // person working through their own filing wants. The count is over one subject's documents,
        // already narrowed by whatever filters are set, so it is cheap at any size a personal
        // document set reaches.
        //
        // The ordering below is unchanged and still load-bearing: `id` is a UUIDv7, so descending id
        // is newest-first with no ties. That was adopted to fix a cursor bug and happens to be
        // exactly what offset pagination wants too — a stable, unique sort key.
        //
        // The trade-off offset brings is that a document arriving while someone is on page 3 shifts
        // every row after it. Acceptable here: uploads announce themselves over the documents
        // channel and the list reloads, so the page a person is looking at is re-derived rather
        // than left stale.
        $paginator = $query->paginate($perPage, ['*'], 'page');

        /** @var list<Document> $documents */
        $documents = $paginator->items();
        $imports = $importState->forMany(
            $tenant->currentTenantId(),
            $documents,
        );
        $proposedNames = self::proposedNames($documents);

        return response()->json(
            $paginator->through(static fn (Document $document): array => [
                'id' => $document->id,
                'filename' => $document->presentedFilename(),
                'type' => $document->type?->value,
                'status' => $document->status->value,
                // What the pipeline is doing, said in the terms a person uses. `status` stays
                // beside it: the two answer different questions and the screen needs both.
                'stage' => DocumentStage::of(
                    $document->status,
                    $imports[$document->id] ?? TransactionImportState::UNAVAILABLE,
                    $document->source,
                )->value,
                'rejection_reason' => $document->rejection_reason?->value,
                'mime_type' => $document->firstPart()?->mime_type,
                'size_bytes' => $document->parts->sum('size_bytes'),
                // How many files this document is made of, so a row can say "4 pagini" without the
                // list asking the parts table once per row.
                'part_count' => $document->part_count,
                // What the newest reading proposed calling it, so the list can say when that name was
                // never taken — and offer to take it for many documents at once.
                'proposed_name' => $proposedNames[$document->id] ?? null,
                'source' => $document->source->value,
                'received_at' => $document->received_at->toIso8601String(),
            ])->toArray(),
        );
    }

    /**
     * The name the newest reading of each document proposed, keyed by document id.
     *
     * One query for the page rather than one per row. Only the newest reading of each document is
     * decrypted: the cast decrypts on access, and the older ones are skipped before anything reads
     * them. Documents come from the scoped query, so no other subject's reading can be reached.
     *
     * @param  list<Document>  $documents
     * @return array<string, string>
     */
    private static function proposedNames(array $documents): array
    {
        $subjects = [];

        foreach ($documents as $document) {
            $subjects[$document->id] = $document->tenantId();
        }

        $names = [];
        $seen = [];

        foreach (DocumentExtraction::query()->whereIn('document_id', array_keys($subjects))->orderByDesc('id')->get() as $extraction) {
            if (isset($seen[$extraction->document_id])) {
                continue;
            }

            $seen[$extraction->document_id] = true;

            $fields = json_decode($extraction->withEncryptionSubjectId($subjects[$extraction->document_id])->result_ciphertext, true);
            $name = is_array($fields) ? ($fields[ExtractionSchema::FIELD_SUGGESTED_FILENAME] ?? null) : null;

            if (is_string($name) && trim($name) !== '') {
                $names[$extraction->document_id] = trim($name);
            }
        }

        return $names;
    }

    /**
     * Narrow to one stage.
     *
     * Four of the seven are a plain status. The other three split `READY` the same way
     * {@see ResolveTransactionImportState} does — expressed here as subqueries, because a list
     * cannot be narrowed by a value computed after the rows have been fetched. The two must agree,
     * and the tests hold them to it: a row shown under a stage must carry that stage's badge.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<Document>  $query
     */
    private function applyStage(mixed $query, DocumentStage $stage, string $subjectId): void
    {
        $status = match ($stage) {
            DocumentStage::CHECKING => DocumentStatus::QUARANTINE,
            DocumentStage::QUEUED => DocumentStatus::SCANNED,
            DocumentStage::PROCESSING => DocumentStatus::PROCESSING,
            DocumentStage::REJECTED => DocumentStatus::REJECTED,
            DocumentStage::ACTION_NEEDED, DocumentStage::TO_IMPORT, DocumentStage::DONE => DocumentStatus::READY,
        };

        $query->where('status', $status->value);

        $imported = self::documentsThatProducedRecords($subjectId);

        if ($stage === DocumentStage::DONE) {
            $query->whereIn('id', $imported);

            return;
        }

        if ($stage !== DocumentStage::ACTION_NEEDED && $stage !== DocumentStage::TO_IMPORT) {
            return;
        }

        $query->whereNotIn('id', $imported);

        $accepted = self::documentsWithAcceptedReading($subjectId);

        if ($stage === DocumentStage::ACTION_NEEDED) {
            // Includes documents nothing could read: there is no proposal to accept, but the person
            // still has to decide what to do about that — retry it, or let it go.
            $query->whereNotIn('id', $accepted);

            return;
        }

        $query->whereIn('id', $accepted);
    }

    /**
     * Documents this subject has already built a transaction from.
     *
     * Constrained to the subject, like every subquery here. Neither could leak another subject's
     * data outward — the outer query is scoped — but an unscoped subquery lets a stranger's row
     * decide what appears in someone's list, and "the ids could never collide" is precisely the
     * reasoning that produces tenancy bugs.
     */
    private static function documentsThatProducedRecords(string $subjectId): callable
    {
        return static function ($sub) use ($subjectId): void {
            // A transaction built from it, or — for a rental contract — the rental created from it.
            $sub->from('financial_transactions')
                ->select('document_id')
                ->where(Tenancy::column(), $subjectId)
                ->whereNotNull('document_id')
                ->union(
                    DB::table('rental_contracts')
                        ->select('source_document_id')
                        ->where(Tenancy::column(), $subjectId)
                        ->whereNotNull('source_document_id'),
                );
        };
    }

    /**
     * Documents with a reading a person has been through.
     *
     * `document_extractions` carries no `tax_subject_id` of its own, so the boundary is enforced by
     * joining back to the document.
     */
    private static function documentsWithAcceptedReading(string $subjectId): callable
    {
        return static function ($sub) use ($subjectId): void {
            $sub->from('document_extractions')
                ->join('documents', 'documents.id', '=', 'document_extractions.document_id')
                ->where('documents.' . Tenancy::column(), $subjectId)
                ->select('document_extractions.document_id')
                ->whereIn('document_extractions.status', ResolveTransactionImportState::ACCEPTED_STATUSES);
        };
    }
}
