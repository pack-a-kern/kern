<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentDerivative;
use App\Domain\Documents\Models\DocumentPart;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\Exceptions\ObjectNotFoundException;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Throwable;

/**
 * Folds other documents into one, as further pages of it.
 *
 * What merging two lookalike transactions does to their evidence: a receipt photographed once and
 * uploaded twice, or a receipt and the same payment's invoice, become one document holding every
 * page — so the surviving transaction points at all of the evidence rather than half of it.
 *
 * ## The bytes are re-encrypted, not relabelled
 *
 * A part's object key names its document (`subjects/{s}/documents/{d}/parts/{p}/original.enc`), and
 * that path is bound into how the object was encrypted — an object moved to another document's path
 * fails to decrypt, deliberately, so a ciphertext cannot be passed off as belonging somewhere else.
 * So each page is read out under its old key and written again under the survivor's, and only the
 * row is re-pointed. The old objects are deleted once the database has committed: an object deleted
 * for a merge that then rolled back would be unrecoverable, while a copy orphaned by a rollback is
 * merely inert.
 *
 * ## What else moves with a page
 *
 * Its scan records and the document's readings, re-pointed at the survivor, so the history of what
 * was checked and what was read survives the document it was first recorded against. The same page
 * appearing twice — two uploads of one photograph — is kept once, by content hash.
 *
 * ## What does not
 *
 * Derivatives (previews, text layers) are dropped: they are regenerable from the originals, and
 * re-keying them would be work spent preserving a cache. The absorbed documents are deleted, and the
 * audit chain records that each one was merged, and into what.
 */
final readonly class MergeDocuments
{
    public function __construct(
        private EncryptedObjectStore $store,
        private AuditChainService $audit,
    ) {
    }

    /**
     * @param  list<string>  $absorbedDocumentIds
     */
    public function __invoke(Document $survivor, array $absorbedDocumentIds, ?string $actorId = null): void
    {
        // Tenant-scoped by the model: a document belonging to someone else is not found, not merged.
        $absorbed = Document::query()
            ->with('parts')
            ->whereKey($absorbedDocumentIds)
            ->whereKeyNot($survivor->getKey())
            ->get()
            ->all();

        if ($absorbed === []) {
            return;
        }

        $survivorId = self::id($survivor);
        $subjectId = $survivor->tax_subject_id;
        $obsoleteKeys = [];

        DB::transaction(function () use ($survivor, $survivorId, $subjectId, $absorbed, $actorId, &$obsoleteKeys): void {
            $known = [];
            $position = 0;

            foreach ($survivor->parts()->get() as $part) {
                $known[$part->sha256] = true;
                $position = max($position, $part->position);
            }

            foreach ($absorbed as $document) {
                $documentId = self::id($document);

                foreach ($document->parts as $part) {
                    $obsoleteKeys[] = $part->objectKey();

                    if (isset($known[$part->sha256])) {
                        // The same page twice. Kept once; its scan record stays with the survivor.
                        DB::table('document_scans')->where('document_part_id', $part->id)->update(['document_id' => $survivorId]);
                        DB::table('document_parts')->where('id', $part->id)->delete();

                        continue;
                    }

                    $known[$part->sha256] = true;
                    $position++;

                    $key = ObjectKey::forDocumentPart($subjectId, $survivorId, self::id($part), DocumentObjectKind::ORIGINAL);
                    $this->copy($part->objectKey(), $key);

                    DB::table('document_parts')->where('id', $part->id)->update([
                        'document_id' => $survivorId,
                        'position' => $position,
                        'object_path' => $key->value,
                        'updated_at' => now('UTC'),
                    ]);
                }

                DB::table('document_scans')->where('document_id', $documentId)->update(['document_id' => $survivorId]);
                DB::table('document_extractions')->where('document_id', $documentId)->update(['document_id' => $survivorId]);

                foreach (DocumentDerivative::query()->where('document_id', $documentId)->get() as $derivative) {
                    $obsoleteKeys[] = ObjectKey::forDocument($subjectId, $documentId, $derivative->kind);
                }

                DocumentDerivative::query()->where('document_id', $documentId)->delete();
                $document->forceDelete();

                $this->audit->record(AuditEventInput::occurringNow(
                    subjectId: $subjectId,
                    actorId: $actorId,
                    eventType: 'DocumentMergedInto',
                    resourceType: 'Document',
                    resourceId: $documentId,
                    payloadHash: $this->audit->hashPayload([
                        'into' => $survivorId,
                        'part_count' => $document->parts->count(),
                    ]),
                ));
            }

            $this->refreshContentHash($survivor, $survivorId, $actorId);
        });

        // After the outer commit, when there is one: a merge running inside a larger transaction
        // must not delete the originals of a copy that is about to be rolled back.
        DB::afterCommit(function () use ($obsoleteKeys): void {
            foreach ($obsoleteKeys as $key) {
                $this->forget($key);
            }
        });
    }

    /**
     * The survivor's part count and content hash, recomputed from the pages it now holds.
     *
     * `documents.sha256` is what upload duplicate-detection compares against. Left as it was, the
     * merged document would no longer be recognised when either original file was uploaded again.
     */
    private function refreshContentHash(Document $survivor, string $survivorId, ?string $actorId): void
    {
        $hashes = array_values(array_filter(
            DB::table('document_parts')->where('document_id', $survivorId)->orderBy('position')->pluck('sha256')->all(),
            is_string(...),
        ));

        DB::table('documents')->where('id', $survivorId)->update([
            'part_count' => count($hashes),
            'sha256' => Document::contentHash($hashes),
            'updated_at' => now('UTC'),
        ]);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $survivor->tax_subject_id,
            actorId: $actorId,
            eventType: 'DocumentsMerged',
            resourceType: 'Document',
            resourceId: $survivorId,
            payloadHash: $this->audit->hashPayload(['part_count' => count($hashes)]),
        ));
    }

    private function copy(ObjectKey $from, ObjectKey $to): void
    {
        $stream = $this->store->get($from);

        try {
            $this->store->put($to, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Best-effort, as in `DeleteDocument`: an orphaned object is inert, a blocked merge is not. */
    private function forget(ObjectKey $key): void
    {
        try {
            $this->store->delete($key);
        } catch (ObjectNotFoundException) {
            // Already gone.
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function id(Document|DocumentPart $model): string
    {
        $id = $model->getKey();

        return is_scalar($id) ? (string) $id : '';
    }
}
