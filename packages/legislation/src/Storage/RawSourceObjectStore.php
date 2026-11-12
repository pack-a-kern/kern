<?php

declare(strict_types=1);

namespace Kern\Legislation\Storage;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Kern\Legislation\Storage\Exceptions\RawObjectNotFoundException;

/**
 * Immutable, content-addressed raw storage for official source documents (`BLUEPRINT.md` §9.1 "never
 * overwrite a raw official source" / §4.9 document integrity), independent of
 * `Kern\Documents\ObjectStore\EncryptedObjectStore`.
 *
 * **Why not the encrypted object store from `docs/handoff/012-encrypted-object-store.md`:** that
 * module's `ObjectKey::forDocument()` is constructed only from a tenant id, a
 * `document_id`, and a closed {@see \Kern\Documents\ObjectStore\DocumentObjectKind} enum, and its
 * `EncryptedObjectStore::put()`/`get()` unwrap a *per-subject* DEK
 * (`Kern\Platform\KeyManagement\SubjectDekService::plaintextDekFor($subjectId)`) for every
 * operation. An official source document (BNR's daily FX feed, an ANAF instruction PDF) belongs to
 * no `TaxSubject` — it is public, not PII (`BLUEPRINT.md` itself draws this line: "Documentele nu se
 * urcă plaintext" governs subject documents; §8/§9 governs official sources with no such
 * requirement). Forcing a source document through that API would mean inventing a fake subject id
 * to satisfy a type contract that was deliberately built narrower than that, or changing
 * `Kern\Documents\ObjectStore` — out of this task's scope
 * (`docs/handoff/014-source-registry-bnr.md` §"Scope"). This class is the intentionally smaller
 * alternative: no encryption (the content is public), no per-subject anything, same underlying
 * Flysystem disk (so operationally it is still "the object store", just a different logical
 * bucket path prefix — `subjects/` vs `sources/`).
 *
 * **Immutability by construction, not by convention**: the storage key is
 * `{prefix}/{source_code}/{sha256(content)}`. Two fetches of byte-identical content always
 * resolve to the same key and `put()` is skipped if that key already exists — this *is* the
 * "never overwrite, and re-ingesting the same bytes is a no-op" property, not a rule enforced by
 * a separate check. Two fetches of different content always resolve to different keys — a raw
 * source document can therefore never be overwritten with different bytes even by a bug, because
 * a different hash is a different path.
 */
final readonly class RawSourceObjectStore
{
    public function __construct(
        private FilesystemFactory $filesystems,
        private string $disk,
        private string $prefix,
    ) {
    }

    public function store(string $sourceCode, string $bytes): StoredRawObject
    {
        $sha256 = hash('sha256', $bytes);
        $path = $this->pathFor($sourceCode, $sha256);

        $disk = $this->filesystems->disk($this->disk);

        if (! $disk->exists($path)) {
            $disk->put($path, $bytes);
        }

        return new StoredRawObject($path, $sha256, strlen($bytes));
    }

    public function retrieve(string $path): string
    {
        $content = $this->filesystems->disk($this->disk)->get($path);

        if ($content === null) {
            throw RawObjectNotFoundException::forPath($path);
        }

        return $content;
    }

    /**
     * Removes a stored page nothing points at any more — a deleted law's. Never one still named by a
     * version: the bytes are shared by every version that read them, so the caller checks that first.
     */
    public function forget(string $path): void
    {
        $this->filesystems->disk($this->disk)->delete($path);
    }

    private function pathFor(string $sourceCode, string $sha256): string
    {
        return sprintf('%s/%s/%s', $this->prefix, strtolower($sourceCode), $sha256);
    }
}
