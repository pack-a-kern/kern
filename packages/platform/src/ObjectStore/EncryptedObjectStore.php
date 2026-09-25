<?php

declare(strict_types=1);

namespace Kern\Platform\ObjectStore;

use Illuminate\Contracts\Filesystem\Filesystem;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\ObjectStore\Exceptions\ObjectIntegrityException;
use Kern\Platform\ObjectStore\Exceptions\ObjectNotFoundException;
use Kern\Platform\ObjectStore\ValueObjects\ObjectManifest;
use RuntimeException;

/**
 * The encrypted object store (BLUEPRINT.md §4.6/§4.9). Every document, OCR derivative, and
 * declaration artifact this product stores goes through `put()`/`get()`.
 *
 * Content is streamed through {@see EnvelopeCipher} under the subject's own DEK
 * ({@see SubjectDekService}) before it ever reaches the configured Flysystem disk — plaintext is
 * never written to storage, not even transiently. `put()`/`get()` only ever buffer through local
 * `php://temp` streams (which spill to a local temp file past a small threshold and are never
 * themselves uploaded/downloaded), so a large document is read/written in bounded chunks rather
 * than held wholly in memory — see {@see StreamEnvelopeWriter}/{@see StreamEnvelopeReader} for
 * the exact framing this relies on.
 *
 * See docs/handoff/012-encrypted-object-store.md for the full contract other modules build on.
 */
final class EncryptedObjectStore
{
    /** php://temp keeps this many bytes in memory before spilling to a local temp file. */
    private const int TEMP_MEMORY_THRESHOLD_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly Filesystem $disk,
        private readonly SubjectDekService $dekService,
        private readonly int $chunkSize,
    ) {
    }

    /**
     * Encrypts `$plaintext` under `$key`'s subject DEK and stores it, alongside an encrypted
     * integrity manifest, on the configured disk. Content is written before the manifest: a
     * `get()` on a `put()` that failed partway through therefore reports "manifest missing"
     * (a clean, safe failure) rather than trusting an orphaned manifest against absent or
     * incomplete content.
     *
     * @param resource $plaintext readable stream; the caller retains ownership and must close it.
     */
    public function put(ObjectKey $key, $plaintext): ObjectManifest
    {
        $dek = $this->dekService->plaintextDekFor($key->subjectId);

        $sealed = self::tempStream();

        try {
            $manifest = (new StreamEnvelopeWriter($this->chunkSize))->write($plaintext, $sealed, $key, $dek);

            rewind($sealed);

            if ($this->disk->writeStream($key->value, $sealed) === false) {
                throw new RuntimeException(sprintf('Failed writing object "%s" to the object store disk.', $key->value));
            }
        } finally {
            fclose($sealed);
        }

        $this->disk->put($key->manifestKey(), EnvelopeCipher::seal(
            $manifest->toJson(),
            $dek,
            ObjectStoreAssociatedData::forManifest($key),
        ));

        return $manifest;
    }

    /**
     * @return resource a readable, rewound stream of verified plaintext. The caller owns it and
     *                   must `fclose()` it. Never returned before every frame and the manifest
     *                   tally have verified in full (BLUEPRINT.md §4.6 "never return content that
     *                   failed its check").
     *
     * @throws ObjectNotFoundException if the content or its manifest does not exist.
     * @throws ObjectIntegrityException if verification fails for any reason.
     */
    public function get(ObjectKey $key)
    {
        $dek = $this->dekService->plaintextDekFor($key->subjectId);
        $manifest = $this->readManifest($key, $dek);

        $source = $this->disk->readStream($key->value);

        if ($source === null) {
            throw ObjectNotFoundException::contentMissing($key->value);
        }

        $plaintext = self::tempStream();

        try {
            (new StreamEnvelopeReader())->read($source, $plaintext, $key, $dek, $manifest);
        } finally {
            fclose($source);
        }

        rewind($plaintext);

        return $plaintext;
    }

    /**
     * Deletes both the content object and its manifest. Deleting only one deliberately isn't
     * exposed: a manifest without content is useless, and content without a manifest can never
     * be retrieved (`get()` always reads the manifest first) — BLUEPRINT.md §25 retention deletes a
     * document as one unit.
     */
    public function delete(ObjectKey $key): void
    {
        $this->disk->delete($key->value);
        $this->disk->delete($key->manifestKey());
    }

    private function readManifest(ObjectKey $key, string $dek): ObjectManifest
    {
        $sealed = $this->disk->get($key->manifestKey());

        if ($sealed === null) {
            throw ObjectNotFoundException::manifestMissing($key->value);
        }

        try {
            $json = EnvelopeCipher::open($sealed, $dek, ObjectStoreAssociatedData::forManifest($key));
        } catch (EnvelopeException) {
            throw ObjectIntegrityException::manifestAuthenticationFailed($key->value);
        }

        return ObjectManifest::fromJson($json);
    }

    /** @return resource */
    private static function tempStream()
    {
        $stream = fopen('php://temp/maxmemory:' . self::TEMP_MEMORY_THRESHOLD_BYTES, 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Failed opening a temporary stream for object store buffering.');
        }

        return $stream;
    }
}
