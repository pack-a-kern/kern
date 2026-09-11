<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Enums\ExtractionStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function __invoke(Request $request, string $subject, TenantContext $tenant): JsonResponse
    {
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
            'cursor' => ['sometimes', 'string'],
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
        $cursor = $validated['cursor'] ?? null;

        $paginator = $query->cursorPaginate($perPage, ['*'], 'cursor', is_string($cursor) ? $cursor : null);

        /** @var list<Document> $documents */
        $documents = $paginator->items();
        $pending = $this->pendingExtractionCounts($documents);

        return response()->json(
            $paginator->through(static fn (Document $document): array => [
                'id' => $document->id,
                'filename' => $document->presentedFilename(),
                'type' => $document->type->value,
                'status' => $document->status->value,
                'rejection_reason' => $document->rejection_reason?->value,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'source' => $document->source->value,
                'received_at' => $document->received_at->toIso8601String(),
                // How many proposals are waiting on a human. Zero and "none yet extracted" look the
                // same here on purpose: both mean there is nothing for a reviewer to do.
                'pending_extractions' => $pending[$document->id] ?? 0,
            ])->toArray(),
        );
    }

    /**
     * Proposed-extraction counts for a page of documents, in one query rather than one per row.
     *
     * @param  list<Document>  $documents
     * @return array<string, int>
     */
    private function pendingExtractionCounts(array $documents): array
    {
        $ids = array_map(static fn (Document $document): string => $document->id, $documents);

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('document_extractions')
            ->whereIn('document_id', $ids)
            ->where('status', ExtractionStatus::PROPOSED->value)
            ->groupBy('document_id')
            ->selectRaw('document_id, count(*) as pending')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $documentId = $row->document_id;
            $counts[is_scalar($documentId) ? (string) $documentId : ''] = (int) $row->pending;
        }

        return $counts;
    }
}
