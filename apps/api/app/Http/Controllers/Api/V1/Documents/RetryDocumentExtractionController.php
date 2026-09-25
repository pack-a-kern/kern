<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Jobs\ProcessScannedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentExtraction;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/subjects/{subject}/documents/{document}/extractions/retry` — run the reading step
 * again for a document that already passed the virus scan.
 *
 * The remedy the review screen offers alongside a recorded failure. Before this existed there was
 * no supported way back: `ProcessScannedDocument` sets `$tries = 1` and catches its own failure, so
 * nothing reaches `failed_jobs` and `queue:retry` has nothing to work with — re-running a reading
 * meant re-dispatching the job by hand.
 *
 * Re-dispatching the whole job rather than only the external call is deliberate: the deterministic
 * parsers run first and may well succeed on a document the model choked on, and a parser result is
 * reproducible where a model's is not. Everything the job repeats before that point is idempotent.
 *
 * Two guards, both about not doing something twice that should only happen once:
 *
 *   - Nothing already proposed. A second reading would sit next to the first with no way to tell a
 *     reviewer which of two disagreeing proposals to believe — and the point of a retry is a
 *     document with *no* proposal.
 *   - Exactly one reading in flight, enforced by moving `READY -> PROCESSING` as a conditional
 *     `UPDATE` and dispatching only if this request is the one that moved it.
 *
 * The second guard is a compare-and-swap rather than a read followed by a dispatch, because a read
 * proves nothing by the time the dispatch happens. Three quick clicks previously produced three
 * concurrent jobs, three proposals, and — the part that actually matters — three separate sends of
 * somebody's document to an external provider, each one a thing ADR 0005 gates individually.
 *
 * Claiming the document also makes the state honest for whoever is looking at it: `PROCESSING` is
 * what the review screen renders as work in progress, and it is true from the moment the request is
 * accepted rather than from whenever a worker happens to pick the job up.
 */
final class RetryDocumentExtractionController
{
    public function __invoke(string $subject, string $document): JsonResponse
    {
        // Tenant-scoped: another subject's document is not found rather than re-read.
        $model = Document::query()->findOrFail($document);

        if (DocumentExtraction::query()->where('document_id', $model->id)->exists()) {
            return response()->json([
                'message' => 'This document already has a proposed extraction.',
                'code' => 'EXTRACTION_ALREADY_EXISTS',
            ], 409);
        }

        // Claim the document. The `where` on the current status is the whole guard: concurrent
        // requests all issue this UPDATE, the database serialises them, and exactly one sees a row
        // affected. The losers never dispatch.
        $claimed = Document::query()
            ->whereKey($model->id)
            ->where('status', DocumentStatus::READY->value)
            ->update(['status' => DocumentStatus::PROCESSING->value]);

        if ($claimed !== 1) {
            // Losing the claim covers every "not now" there is — another request got there first, the
            // virus scan has not finished, the document was rejected. One code, plus the status that
            // says which, rather than a taxonomy of codes the client would have to keep in step.
            $model->refresh();

            return response()->json([
                'message' => 'This document is not ready to be read again.',
                'code' => 'DOCUMENT_NOT_READY',
                'status' => $model->status->value,
            ], 409);
        }

        ProcessScannedDocument::dispatch($model->id);

        // 202: the reading happens on the queue, and the client learns the result from the
        // `DocumentAiExtractionFinished` / `DocumentExtractionProposed` broadcast, not from here.
        return response()->json([], 202);
    }
}
