<?php

declare(strict_types=1);

namespace App\Support\ObjectStore;

use App\Support\Crypto\EnvelopeCipher;
use App\Support\ObjectStore\ValueObjects\ObjectManifest;
use RuntimeException;

/**
 * Streams plaintext from a readable resource into the encrypted container format this module
 * stores. Reads and seals at most `$chunkSize` plaintext bytes at a time, so encrypting a large
 * document never holds more than one chunk of plaintext in memory at once
 * (BLUEPRINT.md §4.6/§14.2).
 *
 * Container format (see docs/handoff/012-encrypted-object-store.md for the authoritative copy),
 * all integers big-endian:
 *
 *     bytes 0-3   magic "TXOS"
 *     byte 4      container format version (currently 1)
 *     repeated for each chunk, in order, until the plaintext source reaches EOF:
 *       bytes 0-3   sealed frame length in bytes (uint32)
 *       remainder   `EnvelopeCipher::seal()` output for this chunk (ASCII base64 text)
 *
 * Each frame is sealed with {@see EnvelopeCipher} under the subject's DEK, with AAD
 * `ObjectStoreAssociatedData::forChunk($key, $chunkIndex)` — binding the frame to this exact
 * subject, this exact object key, and this exact zero-based position in the sequence. A frame
 * moved to a different position or duplicated into another position therefore fails AEAD
 * authentication when {@see StreamEnvelopeReader} re-derives the AAD for the position it reads it
 * at; a dropped or appended frame changes the final frame count against the manifest's recorded
 * `chunkCount`. Zero plaintext bytes (an empty document) is valid: the header is written and the
 * loop simply produces zero frames.
 */
final class StreamEnvelopeWriter
{
    private const string MAGIC = 'TXOS';

    private const int FORMAT_VERSION = 1;

    public function __construct(private readonly int $chunkSize)
    {
        if ($chunkSize < 1) {
            throw new RuntimeException('Object store chunk size must be at least 1 byte.');
        }
    }

    /**
     * @param resource $source readable stream, positioned at the start of the plaintext.
     * @param resource $destination writable stream; only the sealed container is ever written
     *                               here, never plaintext.
     * @param string $dek raw 32-byte plaintext DEK for `$key`'s subject.
     */
    public function write($source, $destination, ObjectKey $key, string $dek): ObjectManifest
    {
        if (fwrite($destination, self::MAGIC . chr(self::FORMAT_VERSION)) === false) {
            throw new RuntimeException('Failed writing object store container header.');
        }

        $hashContext = hash_init('sha256');
        $totalBytes = 0;
        $chunkIndex = 0;
        $chunkSize = $this->chunkSize;
        assert($chunkSize > 0); // enforced above; re-asserted so static analysis can narrow fread()'s length argument.

        while (! feof($source)) {
            $chunk = fread($source, $chunkSize);

            if ($chunk === false) {
                throw new RuntimeException('Failed reading plaintext source stream.');
            }

            if ($chunk === '') {
                break;
            }

            hash_update($hashContext, $chunk);
            $totalBytes += strlen($chunk);

            $sealed = EnvelopeCipher::seal($chunk, $dek, ObjectStoreAssociatedData::forChunk($key, $chunkIndex));
            $frame = pack('N', strlen($sealed)) . $sealed;

            if (fwrite($destination, $frame) === false) {
                throw new RuntimeException('Failed writing object store container frame.');
            }

            $chunkIndex++;
        }

        return new ObjectManifest(
            sha256: hash_final($hashContext),
            sizeBytes: $totalBytes,
            chunkSize: $this->chunkSize,
            chunkCount: $chunkIndex,
            formatVersion: self::FORMAT_VERSION,
        );
    }
}
