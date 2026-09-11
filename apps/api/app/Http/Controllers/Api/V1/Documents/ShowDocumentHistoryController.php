<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentScan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/history` — what the system did to this
 * document, and what it can prove about it.
 *
 * Two lists, from two different kinds of record:
 *
 *  - **scans**, from `document_scans`: which engine cleared this file, against which signature
 *    database, when, and how long it took. The operational fact.
 *  - **events**, from `audit_events`: the same milestones as entries in this subject's append-only
 *    hash chain (`BLUEPRINT.md` §4.8), each carrying its sequence number and event hash. The
 *    tamper-evident fact.
 *
 * ## Why this is not the admin audit screen
 *
 * `/api/v1/audit/*` spans every tax subject and names every actor, which is why it sits behind the
 * `audit.view` permission. This endpoint is the opposite shape: one document, one subject, scoped
 * by the same `tax-subject` middleware as every other document route. A person is entitled to see
 * what was done to their own document — being unable to answer "was this scanned?" without an
 * administrator is not a security property, it is an unanswerable question.
 *
 * Returned in the order things happened, oldest first, because this is read as a story.
 */
final class ShowDocumentHistoryController extends Controller
{
    /**
     * Event types this document's own lifecycle produces. An allowlist rather than "everything
     * mentioning this id": the chain is shared with actions that are not this screen's business,
     * and a future event type should have to be added here deliberately.
     */
    private const array LIFECYCLE_EVENT_TYPES = [
        'DocumentUploaded',
        'DocumentScanned',
        'DocumentTextExtracted',
        'DocumentRejected',
        'DocumentExtractionAccepted',
        'DocumentExtractionCorrected',
    ];

    public function __invoke(string $subject, string $document): JsonResponse
    {
        // Tenant-scoped: another subject's document is not found rather than described.
        $model = Document::query()->findOrFail($document);

        $scans = DocumentScan::query()
            ->where('document_id', $model->id)
            ->orderBy('scanned_at')
            ->get()
            ->map(static fn (DocumentScan $scan): array => [
                'id' => $scan->id,
                'outcome' => $scan->outcome->value,
                'scanner' => $scan->scanner,
                'scanner_version' => $scan->scanner_version,
                'definitions_version' => $scan->definitions_version,
                'duration_ms' => $scan->duration_ms,
                'scanned_at' => $scan->scanned_at->toIso8601String(),
            ])
            ->all();

        // Read through the query builder rather than an Eloquent model: `audit_events` is
        // append-only and belongs to Domain/Audit, and this is a read of a few rows by id — not a
        // reason to hand another module a writable model of a chain it must never write to.
        $events = DB::table('audit_events')
            ->where('resource_type', 'Document')
            ->where('resource_id', $model->id)
            ->whereIn('event_type', self::LIFECYCLE_EVENT_TYPES)
            ->orderBy('occurred_at')
            ->get()
            ->map(static fn (object $event): array => [
                'event_type' => self::str($event->event_type),
                'sequence' => (int) $event->sequence,
                'actor_id' => $event->actor_id === null ? null : self::str($event->actor_id),
                'occurred_at' => self::str($event->occurred_at),
                // The chain link itself, so the screen can show that this entry is anchored rather
                // than merely asserted. Truncation is the caller's business, not this endpoint's.
                'event_hash' => self::str($event->event_hash),
            ])
            ->all();

        return response()->json([
            'scans' => $scans,
            'events' => $events,
        ]);
    }

    /** The query builder hands back `mixed`; a bare cast on that is a static-analysis error. */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
