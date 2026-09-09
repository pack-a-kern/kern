<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\DocumentScanned;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Documents\Scanning\ScanOutcome;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * `BLUEPRINT.md` §14.1 ClamAV stage. Runs entirely against `QuarantineObjectStore` until (and
 * unless) a clean verdict is reached — the quarantined bytes are never even read by the real
 * `EncryptedObjectStore` before that.
 *
 * **This job throwing is the mechanism, not a bug**, whenever `VirusScanner::scan()` cannot
 * produce a verdict: Laravel's queue retry/backoff takes over, and `documents.status` simply never
 * advances past `QUARANTINE` — no code path here ever writes `SCANNED` on anything but an actual
 * clean verdict. This is `docs/AGENTS.md` §21.4's "unreachable scanner blocks" requirement,
 * satisfied structurally rather than by an explicit check.
 *
 * Resolves `Document` via `withoutGlobalScope(TaxSubjectScope::class)` — this job is the system's
 * own trusted pipeline acting on a specific, already-known document id, not a user request; there
 * is no per-job authenticated user to bind `App\Domain\Subjects\Tenancy\TenantContext` against
 * (the same "genuinely cross-tenant system operation" category `ScopedToTaxSubject`'s own docblock
 * names for admin tooling).
 */
final class ScanQuarantinedDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $documentId)
    {
    }

    public function handle(VirusScanner $scanner, QuarantineObjectStore $quarantineStore, EncryptedObjectStore $realStore): void
    {
        $document = Document::withoutGlobalScope(TaxSubjectScope::class)->findOrFail($this->documentId);
        $objectKey = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);

        $scanStream = $quarantineStore->get($objectKey);

        try {
            $outcome = $scanner->scan($scanStream);
        } finally {
            fclose($scanStream);
        }

        if ($outcome === ScanOutcome::INFECTED) {
            $quarantineStore->delete($objectKey);

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

        $document->status = DocumentStatus::SCANNED;
        $document->save();

        DocumentScanned::dispatch($document->id, $document->tax_subject_id, DocumentStatus::SCANNED, null);

        ProcessScannedDocument::dispatch($document->id);
    }
}
