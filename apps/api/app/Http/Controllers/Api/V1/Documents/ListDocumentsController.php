<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Enums\DocumentStage;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Queries\SettledDocuments;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
 * (`App\Domain\Subjects\Tenancy\ScopedToTaxSubject`), so the only reader is a user with an active
 * membership on this subject.
 *
 * There is deliberately **no filename search**. The column is encrypted with a random nonce, so it
 * is not searchable without a blind index, and a blind index on a filename would buy very little
 * (`BLUEPRINT.md` §4.4 reserves them for equality lookups that matter, like CNP). Filtering is by
 * the metadata that is stored in clear: status, type and the received date.
 */
final class ListDocumentsController extends Controller
{
    /** Matches `ListDocumentExtractionsController`, so both document lists page identically. */
    private const int DEFAULT_PER_PAGE = 20;

    private const int MAX_PER_PAGE = 100;

    public function __invoke(
        Request $request,
        string $subject,
        TenantContext $tenant,
        SettledDocuments $settledDocuments,
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
        // `id` is a UUIDv7 (`App\Support\Crypto\Concerns\HasUuidPrimaryKey`), whose leading 48
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
        $query = Document::query()->orderByDesc('id');

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
            $this->applyStage($query, DocumentStage::from($validated['stage']), $tenant->currentSubjectId());
        }

        if ($request->boolean('needs_review')) {
            // "Still needs your attention", not "has a machine-made proposal waiting".
            //
            // The first version meant the latter, and it contradicted the screen: a document showed
            // a *Review* button — because it is `READY` and can be opened — while the filter said
            // nothing needed reviewing, because no parser had proposed anything for it. Both were
            // true and together they were nonsense.
            //
            // A document is done when a person has acted on it: accepted a reading, or turned it
            // into a transaction. Everything else that is `READY` is outstanding, whether a parser
            // could read it or not — which matters, because most documents are photographs no
            // parser can read and they need a human precisely *because* of that.
            // Both exclusions are constrained to this subject. Neither could leak another subject's
            // data outward — the outer query is scoped and these only ever *remove* rows — but an
            // unscoped subquery lets a stranger's row decide what appears in someone's list, and
            // "the ids could never collide" is precisely the reasoning that produces tenancy bugs.
            $subjectId = $tenant->currentSubjectId();

            $query->where('status', DocumentStatus::READY->value)
                ->whereNotIn('id', function ($sub) use ($subjectId): void {
                    // `document_extractions` carries no `tax_subject_id` of its own — it is reached
                    // through its document — so the boundary is enforced by joining back to it.
                    $sub->from('document_extractions')
                        ->join('documents', 'documents.id', '=', 'document_extractions.document_id')
                        ->where('documents.tax_subject_id', $subjectId)
                        ->select('document_extractions.document_id')
                        ->whereIn('document_extractions.status', [
                            ExtractionStatus::ACCEPTED->value,
                            ExtractionStatus::REVIEWED->value,
                        ]);
                })
                ->whereNotIn('id', function ($sub) use ($subjectId): void {
                    $sub->from('financial_transactions')
                        ->select('document_id')
                        ->where('tax_subject_id', $subjectId)
                        ->whereNotNull('document_id');
                });
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
        $settled = $settledDocuments(
            $tenant->currentSubjectId(),
            array_map(static fn (Document $document): string => $document->id, $documents),
        );

        return response()->json(
            $paginator->through(static fn (Document $document): array => [
                'id' => $document->id,
                'filename' => $document->presentedFilename(),
                'type' => $document->type->value,
                'status' => $document->status->value,
                // What the pipeline is doing, said in the terms a person uses. `status` stays
                // beside it: the two answer different questions and the screen needs both.
                'stage' => DocumentStage::of($document->status, isset($settled[$document->id]))->value,
                'rejection_reason' => $document->rejection_reason?->value,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'source' => $document->source->value,
                'received_at' => $document->received_at->toIso8601String(),
            ])->toArray(),
        );
    }


    /**
     * Narrow to one stage.
     *
     * Four of the six are a plain status. The other two split `READY` by whether a person has dealt
     * with the document — the same rule `SettledDocuments` uses for the badge, expressed here as
     * subqueries because a list cannot be narrowed by a value computed after the rows are fetched.
     *
     * `ACTION_NEEDED` is exactly what the `needs_review` flag already means. Both are kept: the flag
     * is part of the API other callers may use, and this is the one the screen now drives.
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
            DocumentStage::ACTION_NEEDED, DocumentStage::DONE => DocumentStatus::READY,
        };

        $query->where('status', $status->value);

        if ($stage !== DocumentStage::ACTION_NEEDED && $stage !== DocumentStage::DONE) {
            return;
        }

        $settled = static function ($sub) use ($subjectId): void {
            $sub->from('document_extractions')
                ->join('documents', 'documents.id', '=', 'document_extractions.document_id')
                ->where('documents.tax_subject_id', $subjectId)
                ->select('document_extractions.document_id')
                ->whereIn('document_extractions.status', [
                    ExtractionStatus::ACCEPTED->value,
                    ExtractionStatus::REVIEWED->value,
                ]);
        };
        $used = static function ($sub) use ($subjectId): void {
            $sub->from('financial_transactions')
                ->select('document_id')
                ->where('tax_subject_id', $subjectId)
                ->whereNotNull('document_id');
        };

        if ($stage === DocumentStage::DONE) {
            $query->where(static function ($outer) use ($settled, $used): void {
                $outer->whereIn('id', $settled)->orWhereIn('id', $used);
            });

            return;
        }

        $query->whereNotIn('id', $settled)->whereNotIn('id', $used);
    }
}
