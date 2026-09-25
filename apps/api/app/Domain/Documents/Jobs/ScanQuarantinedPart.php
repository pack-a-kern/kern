<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\DocumentScanned;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentPart;
use App\Domain\Documents\Models\DocumentScan;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Documents\Scanning\ScanOutcome;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\Tenancy\TenantScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * `BLUEPRINT.md` §14.1 ClamAV stage, for one file of a document.
 *
 * Per part, because ClamAV scans bytes: there is no such thing as scanning a logical document, and
 * a "document scan" that only looked at page 1 would be a way to smuggle a file in behind a clean
 * sibling. Every part gets its own scan, its own `document_scans` row and its own audit event.
 *
 * Runs entirely against `QuarantineObjectStore` until (and unless) a clean verdict is reached — the
 * quarantined bytes are never even read by the real `EncryptedObjectStore` before that.
 *
 * **This job throwing is the mechanism, not a bug**, whenever `VirusScanner::scan()` cannot produce
 * a verdict: Laravel's queue retry/backoff takes over, and the part simply never advances past
 * `QUARANTINE` — no code path here ever writes `SCANNED` on anything but an actual clean verdict.
 * This is `docs/AGENTS.md` §21.4's "unreachable scanner blocks" requirement, satisfied structurally
 * rather than by an explicit check.
 *
 * Resolves models via `withoutGlobalScope(TenantScope::class)` — this job is the system's own
 * trusted pipeline acting on a specific, already-known part, not a user request; there is no
 * per-job authenticated user to bind `App\Support\Tenancy\TenantContext` against.
 */
final class ScanQuarantinedPart implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $documentPartId)
    {
    }

    public function handle(
        VirusScanner $scanner,
        QuarantineObjectStore $quarantineStore,
        EncryptedObjectStore $realStore,
        AuditChainService $audit,
    ): void {
        // Gone means the document was deleted while this waited in the queue. Deleting removes its
        // parts and their bytes, so there is nothing left to scan — and failing would only leave a
        // failed job behind for an upload the person already threw away.
        $part = DocumentPart::query()->find($this->documentPartId);
        $document = $part === null
            ? null
            : Document::withoutGlobalScope(TenantScope::class)->find($part->document_id);

        if ($part === null || $document === null) {
            return;
        }

        $part->setRelation('document', $document);

        $objectKey = $part->objectKey();

        $scanStream = $quarantineStore->get($objectKey);
        $startedAt = microtime(true);

        try {
            $outcome = $scanner->scan($scanStream);
        } finally {
            fclose($scanStream);
        }

        // Recorded before either branch acts on the verdict, so the evidence that a scan happened
        // exists whatever is done about it — and so a crash between verdict and consequence leaves
        // the scan visible rather than invisible.
        $this->recordScan($document, $part, $scanner, $outcome, $startedAt, $audit);

        if ($outcome === ScanOutcome::INFECTED) {
            $quarantineStore->delete($objectKey);

            $part->status = DocumentStatus::REJECTED;
            $part->rejection_reason = RejectionReason::INFECTED;
            $part->save();

            // One infected part rejects the whole document. The clean siblings keep their own
            // SCANNED status and their bytes — what they are is a fact about them, and a person
            // looking at why their invoice was refused should be able to see which page did it.
            $document->status = DocumentStatus::REJECTED;
            $document->rejection_reason = RejectionReason::INFECTED;
            $document->save();

            DocumentScanned::dispatch($document->id, $document->tax_subject_id, DocumentStatus::REJECTED, RejectionReason::INFECTED);

            return;
        }

        $promoteStream = $quarantineStore->get($objectKey);

        try {
            $realStore->put($objectKey, $promoteStream);
        } finally {
            fclose($promoteStream);
        }

        $quarantineStore->delete($objectKey);

        $part->status = DocumentStatus::SCANNED;
        $part->save();

        if ($this->promoteDocument($document)) {
            DocumentScanned::dispatch($document->id, $document->tax_subject_id, DocumentStatus::SCANNED, null);

            ProcessScannedDocument::dispatch($document->id);
        }
    }

    /**
     * Advance the document to `SCANNED`, but only once, and only when every part is clean.
     *
     * Parts are scanned concurrently, so the last two finishing at the same moment would both see
     * "all parts clean" and both start reading the document — two extractions, two proposals, one
     * document. The conditional `UPDATE … WHERE status = 'QUARANTINE'` is what makes exactly one of
     * them win: the database decides, and the loser is told it changed no rows.
     *
     * A document already `REJECTED` by an infected sibling is not matched by that condition either,
     * so a clean part finishing after the bad news cannot undo it.
     *
     * @return bool whether this call is the one that promoted it
     */
    private function promoteDocument(Document $document): bool
    {
        $pending = DocumentPart::query()
            ->where('document_id', $document->id)
            ->where('status', '!=', DocumentStatus::SCANNED->value)
            ->exists();

        if ($pending) {
            return false;
        }

        $promoted = DB::table('documents')
            ->where('id', $document->id)
            ->where('status', DocumentStatus::QUARANTINE->value)
            ->update([
                'status' => DocumentStatus::SCANNED->value,
                'updated_at' => now('UTC'),
            ]);

        if ($promoted === 0) {
            return false;
        }

        $document->status = DocumentStatus::SCANNED;
        $document->syncOriginal();

        return true;
    }

    /**
     * Writes the `document_scans` row and the matching audit event.
     *
     * Two records, deliberately, because they answer different questions and have different
     * lifetimes: the row is the operational fact a screen can query and a re-scan can compare
     * against, while the audit event is the tamper-evident entry in the subject's hash chain
     * (`BLUEPRINT.md` §4.8) that cannot be quietly edited later.
     *
     * `actorId` is null: no person scanned this document. Recording the queue worker as the actor
     * would be a fiction, and recording the uploader would be worse — it would read as though they
     * performed the scan.
     *
     * The signature name never appears in either. `ScanOutcome` reduced it to CLEAN/INFECTED at the
     * scanner boundary precisely so attacker-influenced text has nowhere to travel.
     */
    private function recordScan(
        Document $document,
        DocumentPart $part,
        VirusScanner $scanner,
        ScanOutcome $outcome,
        float $startedAt,
        AuditChainService $audit,
    ): void {
        $description = $scanner->describe();

        DocumentScan::create([
            'document_id' => $document->id,
            'document_part_id' => $part->id,
            'outcome' => $outcome,
            'scanner' => $description->name,
            'scanner_version' => $description->engineVersion,
            'definitions_version' => $description->definitionsVersion,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'scanned_at' => now('UTC'),
        ]);

        $audit->record(AuditEventInput::occurringNow(
            subjectId: $document->tax_subject_id,
            actorId: null,
            eventType: 'DocumentScanned',
            resourceType: 'Document',
            resourceId: $document->id,
            payloadHash: $audit->hashPayload([
                'part_position' => $part->position,
                'outcome' => $outcome->value,
                'scanner' => $description->name,
                'scanner_version' => $description->engineVersion,
                'definitions_version' => $description->definitionsVersion,
            ]),
        ));
    }
}
