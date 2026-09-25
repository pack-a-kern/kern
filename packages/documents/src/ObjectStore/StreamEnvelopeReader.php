<?php

declare(strict_types=1);

namespace Kern\Documents\ObjectStore;

use Kern\Documents\ObjectStore\Exceptions\ObjectIntegrityException;
use Kern\Documents\ObjectStore\ValueObjects\ObjectManifest;
use Kern\Platform\Crypto\EnvelopeCipher;
use Kern\Platform\Crypto\Exceptions\EnvelopeException;
use RuntimeException;

/**
 * The inverse of {@see StreamEnvelopeWriter} — see that class's docblock for the exact container
 * format. Streams the container from a readable resource, decrypting and verifying one frame at
 * a time so decrypting a large document never holds more than one chunk of plaintext in memory at
 * once (BLUEPRINT.md §4.6/§14.2), and writes verified plaintext to `$destination` as it goes.
 *
 * `read()` only returns normally once every frame has authenticated *and* the reassembled
 * plaintext's frame count, byte length, and SHA-256 all match `$manifest` exactly — any mismatch
 * throws {@see ObjectIntegrityException} instead of returning. The caller
 * ({@see EncryptedObjectStore}) is what turns this into "never return content that failed its
 * check": it only hands its own destination stream back to its caller after this method has
 * returned without throwing.
 */
final class StreamEnvelopeReader
{
    private const string MAGIC = 'TXOS';

    private const int FORMAT_VERSION = 1;

    private const int HEADER_BYTES = 5; // strlen(MAGIC) + 1 version byte

    private const int LENGTH_PREFIX_BYTES = 4;

    /**
     * @param resource $source readable stream, positioned at the start of the container.
     * @param resource $destination writable stream that receives verified plaintext, in order.
     * @param string $dek raw 32-byte plaintext DEK for `$key`'s subject.
     *
     * @throws ObjectIntegrityException if the header, any frame, or the final tally does not
     *                                   match `$manifest`.
     */
    public function read($source, $destination, ObjectKey $key, string $dek, ObjectManifest $manifest): void
    {
        $header = self::readExactly($source, self::HEADER_BYTES, $key->value);

        if ($header === null || ! str_starts_with($header, self::MAGIC)) {
            throw ObjectIntegrityException::malformedContainer($key->value);
        }

        $version = ord(substr($header, strlen(self::MAGIC), 1));

        if ($version !== self::FORMAT_VERSION) {
            throw ObjectIntegrityException::unsupportedFormatVersion($key->value, $version);
        }

        // Generous upper bound on one sealed frame's byte length: base64 expands the plaintext
        // chunk (plus EnvelopeCipher's fixed ~46-byte header/nonce/tag overhead) by roughly 4/3.
        // This exists purely to reject an obviously-corrupted length prefix before attempting to
        // read that many bytes — it is not itself a security boundary (AEAD authentication is).
        $maxFrameBytes = (int) ceil(($manifest->chunkSize + 128) * 4 / 3) + 128;

        $hashContext = hash_init('sha256');
        $totalBytes = 0;
        $chunkIndex = 0;

        while (true) {
            $lengthBytes = self::readExactly($source, self::LENGTH_PREFIX_BYTES, $key->value, allowCleanEof: true);

            if ($lengthBytes === null) {
                break;
            }

            /** @var array{1:int} $unpacked */
            $unpacked = unpack('N', $lengthBytes);
            $frameLength = $unpacked[1];

            if ($frameLength < 1 || $frameLength > $maxFrameBytes) {
                throw ObjectIntegrityException::malformedContainer($key->value);
            }

            $frame = self::readExactly($source, $frameLength, $key->value);

            if ($frame === null) {
                throw ObjectIntegrityException::malformedContainer($key->value);
            }

            try {
                $plaintext = EnvelopeCipher::open($frame, $dek, ObjectStoreAssociatedData::forChunk($key, $chunkIndex));
            } catch (EnvelopeException $e) {
                throw ObjectIntegrityException::chunkAuthenticationFailed($key->value, $chunkIndex, $e);
            }

            hash_update($hashContext, $plaintext);
            $totalBytes += strlen($plaintext);

            if (fwrite($destination, $plaintext) === false) {
                throw new RuntimeException('Failed writing decrypted plaintext to destination stream.');
            }

            $chunkIndex++;
        }

        if ($chunkIndex !== $manifest->chunkCount) {
            throw ObjectIntegrityException::chunkCountMismatch($key->value, $manifest->chunkCount, $chunkIndex);
        }

        if ($totalBytes !== $manifest->sizeBytes) {
            throw ObjectIntegrityException::sizeMismatch($key->value, $manifest->sizeBytes, $totalBytes);
        }

        if (! hash_equals($manifest->sha256, hash_final($hashContext))) {
            throw ObjectIntegrityException::hashMismatch($key->value);
        }
    }

    /**
     * Reads exactly `$length` bytes from `$stream`.
     *
     * - If `$allowCleanEof` is true and the stream is already at EOF (zero bytes available before
     *   any byte of this call is read), returns `null` — this is the *expected* way the frame
     *   loop above learns "no more frames", not an error.
     * - Any other case where fewer than `$length` bytes can be read (EOF partway through a frame,
     *   or `$allowCleanEof` false — used for the fixed-size header, which must always be fully
     *   present) is a truncated/malformed container and throws.
     *
     * @param resource $stream
     */
    private static function readExactly($stream, int $length, string $objectKey, bool $allowCleanEof = false): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);
            assert($remaining > 0); // guaranteed by the loop condition; re-asserted for static analysis.
            $chunk = fread($stream, $remaining);

            if ($chunk === false || $chunk === '') {
                if ($buffer === '' && $allowCleanEof) {
                    return null;
                }

                throw ObjectIntegrityException::malformedContainer($objectKey);
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
