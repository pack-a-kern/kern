<?php

declare(strict_types=1);

namespace Kern\Documents\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentDerivative;
use Kern\Documents\Models\DocumentPart;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\ObjectStore\Exceptions\ObjectNotFoundException;
use Kern\Platform\ObjectStore\ObjectKey;
use Throwable;

/**
 * Deletes a document: the row, its derivatives, and the encrypted bytes behind both.
 *
 * ## The bytes go too
 *
 * A soft-deleted row with the file still in the object store would be the worst of both — the
 * document is gone as far as anyone can see, and the content is still there to be subpoenaed,
 * leaked or billed for. Someone deleting a document means the content, so the content is what goes.
 *
 * ## What survives, deliberately
 *
 * **Transactions built from it.** `financial_transactions.document_id` is `nullOnDelete`: the figure
 * was real, and losing its evidence is a loss of provenance rather than a reason to silently change
 * someone's income.
 *
 * **The audit chain.** Every entry this document produced stays, and a `DocumentDeleted` entry is
 * added. That is the whole point of an append-only chain — it records that a document existed, was
 * scanned, was read, and was removed, even once nothing else can show it.
 *
 * ## Object-store failures do not block the row
 *
 * A missing object is expected: a rejected upload's bytes were already deleted, and a quarantined
 * one never reached the real store. A store that is briefly unreachable is not a reason to refuse
 * the deletion either — the row and its key go, the orphaned object is inert (nothing can reach it
 * without a row naming its key), and the alternative is a document a person cannot get rid of.
 */
final readonly class DeleteDocument
{
    public function __construct(
        private EncryptedObjectStore $store,
        private AuditChainService $audit,
    ) {
    }

    public function __invoke(Document $document, ?string $actorId = null): void
    {
        // Read before deleting: afterwards the audit entry is the only trace left.
        $subjectId = $document->tenantId();
        $documentId = $document->id;
        $type = $document->type?->value;
        $sha256 = $document->sha256;

        /** @var list<DocumentDerivative> $derivatives */
        $derivatives = DocumentDerivative::query()->where('document_id', $documentId)->get()->all();

        // Read before the rows go: each part knows where its own bytes are, and once
        // `forceDelete()` has cascaded there is nothing left to ask. A document made of four
        // photographs has four objects to forget, not one.
        $partKeys = $document->parts->map(static fn (DocumentPart $part): ObjectKey => $part->objectKey())->all();

        DB::transaction(function () use ($document, $documentId): void {
            DocumentDerivative::query()->where('document_id', $documentId)->delete();

            // `document_extractions` cascades from its own foreign key, and any transaction built
            // from this document keeps its row with a null link.
            $document->forceDelete();
        });

        // After the commit: an object deleted for a row that then failed to commit would be
        // unrecoverable, while an orphaned object is merely inert.
        foreach ($partKeys as $key) {
            $this->forget($key);
        }

        foreach ($derivatives as $derivative) {
            $this->forget(ObjectKey::forDocument($subjectId, $documentId, $derivative->kind));
        }

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $subjectId,
            actorId: $actorId,
            eventType: 'DocumentDeleted',
            resourceType: 'Document',
            resourceId: $documentId,
            // The content hash, not the filename: the filename is encrypted at rest and an audit
            // payload is not the place to undo that, while the hash is what identifies the bytes.
            payloadHash: $this->audit->hashPayload([
                'type' => $type,
                'sha256' => $sha256,
                'part_count' => count($partKeys),
            ]),
        ));
    }

    /** Best-effort: see the class docblock for why a failure here does not undo the deletion. */
    private function forget(ObjectKey $key): void
    {
        try {
            $this->store->delete($key);
        } catch (ObjectNotFoundException) {
            // Already gone — a rejected upload, or a document that never left quarantine.
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
