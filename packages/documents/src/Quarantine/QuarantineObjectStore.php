<?php

declare(strict_types=1);

namespace Kern\Documents\Quarantine;

use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\ObjectKey;
use Kern\Documents\ObjectStore\ValueObjects\ObjectManifest;

/**
 * Quarantine as a property of the design, not a status column someone can forget to check
 * (`BLUEPRINT.md` §14.1/§14.2). This class composes `Kern\Documents\ObjectStore\EncryptedObjectStore`
 * pointed at a **physically separate Flysystem disk** (`config('documents.quarantine_disk')` —
 * a distinct MinIO bucket in every real environment) from the one the rest of the application
 * reads documents from (`config('objectstore.disk')`).
 *
 * This is what makes "unscanned content is unreachable" true independent of any status check:
 * `Kern\Documents\Http\Controllers\ShowDocumentController` (and any future document-
 * download endpoint) is only ever wired to the real `EncryptedObjectStore` — it has no reference
 * to this class at all, so there is no code path by which a controller could accidentally read a
 * quarantined object even if a status check were skipped. The same subject/document DEK and
 * `ObjectKey` shape apply on both disks (a *place*, not a *key scheme*, is what changed), so
 * `Kern\Documents\Jobs\ScanQuarantinedDocument` promotes a clean document by reading
 * plaintext back out of this store and writing it into the real one, then deleting the quarantine
 * copy — never by copying encrypted bytes across disks, which would work today (both disks share
 * the same DEK derivation) but would silently break the moment either store's container format or
 * per-disk key derivation ever diverges.
 *
 * Deliberately composition, not inheritance: this is not "a kind of" `EncryptedObjectStore` that
 * callers should be able to use interchangeably with the real one — the entire point is that it
 * is a distinct, non-substitutable type, so a caller cannot pass a quarantine store where the real
 * store's `EncryptedObjectStore` type-hint is expected, or vice versa.
 */
final readonly class QuarantineObjectStore
{
    public function __construct(private EncryptedObjectStore $store)
    {
    }

    /** @param resource $plaintext caller-owned; caller must close it. */
    public function put(ObjectKey $key, $plaintext): ObjectManifest
    {
        return $this->store->put($key, $plaintext);
    }

    /** @return resource caller-owned; caller must close it. */
    public function get(ObjectKey $key)
    {
        return $this->store->get($key);
    }

    public function delete(ObjectKey $key): void
    {
        $this->store->delete($key);
    }
}
