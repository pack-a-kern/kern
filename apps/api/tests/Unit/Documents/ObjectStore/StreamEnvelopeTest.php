<?php

declare(strict_types=1);

namespace Tests\Unit\Documents\ObjectStore\StreamEnvelopeTest;

use Illuminate\Support\Str;
use Kern\Documents\ObjectStore\DocumentObjectKind;
use Kern\Documents\ObjectStore\Exceptions\ObjectIntegrityException;
use Kern\Documents\ObjectStore\ObjectKey;
use Kern\Documents\ObjectStore\StreamEnvelopeReader;
use Kern\Documents\ObjectStore\StreamEnvelopeWriter;
use Kern\Documents\ObjectStore\ValueObjects\ObjectManifest;
use RuntimeException;

/**
 * Exercises the container format documented on StreamEnvelopeWriter/StreamEnvelopeReader
 * directly — no Laravel container, no filesystem disk, no MinIO. This is deliberately the layer
 * that proves chunking/AAD/tampering/memory-boundedness, since it is cheap enough to run on every
 * `vendor/bin/pest` invocation; docs/handoff/012-encrypted-object-store.md's live-MinIO test
 * proves the same container format survives a real S3-compatible round trip.
 */
function makeKey(): ObjectKey
{
    return ObjectKey::forDocument((string) Str::uuid7(), (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);
}

function makeDek(): string
{
    return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
}

/** @return resource */
function memoryStream(string $initial = '')
{
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Failed opening a memory stream.');
    }

    if ($initial !== '') {
        fwrite($stream, $initial);
        rewind($stream);
    }

    return $stream;
}

/** @return array{0:string, 1:ObjectManifest} [raw container bytes, manifest] */
function writeContainer(string $plaintext, int $chunkSize, ObjectKey $key, string $dek): array
{
    $source = memoryStream($plaintext);
    $destination = memoryStream();

    $manifest = (new StreamEnvelopeWriter($chunkSize))->write($source, $destination, $key, $dek);

    fclose($source);
    rewind($destination);
    $bytes = stream_get_contents($destination);
    fclose($destination);

    if ($bytes === false) {
        throw new RuntimeException('Failed reading back the written container.');
    }

    return [$bytes, $manifest];
}

function readContainer(string $containerBytes, ObjectKey $key, string $dek, ObjectManifest $manifest): string
{
    $source = memoryStream($containerBytes);
    $destination = memoryStream();

    (new StreamEnvelopeReader())->read($source, $destination, $key, $dek, $manifest);

    fclose($source);
    rewind($destination);
    $plaintext = stream_get_contents($destination);
    fclose($destination);

    if ($plaintext === false) {
        throw new RuntimeException('Failed reading back the decrypted plaintext.');
    }

    return $plaintext;
}

/**
 * Splits a container's frames apart for tampering. Deliberately re-implements the same
 * length-prefixed framing StreamEnvelopeWriter documents, so this test is pinned to the exact
 * wire format rather than to any production helper.
 *
 * @return array{0:string, 1:list<string>} [5-byte header, ordered list of sealed frame payloads]
 */
function splitFrames(string $containerBytes): array
{
    $header = substr($containerBytes, 0, 5);
    $offset = 5;
    $frames = [];

    while ($offset < strlen($containerBytes)) {
        /** @var array{1:int} $unpacked */
        $unpacked = unpack('N', substr($containerBytes, $offset, 4));
        $length = $unpacked[1];
        $offset += 4;
        $frames[] = substr($containerBytes, $offset, $length);
        $offset += $length;
    }

    return [$header, $frames];
}

/** @param list<string> $frames */
function joinFrames(string $header, array $frames): string
{
    $out = $header;

    foreach ($frames as $frame) {
        $out .= pack('N', strlen($frame)) . $frame;
    }

    return $out;
}

it('round-trips a single-chunk plaintext', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('hello world', 4 * 1024 * 1024, $key, $dek);

    expect($manifest->chunkCount)->toBe(1)
        ->and($manifest->sha256)->toBe(hash('sha256', 'hello world'))
        ->and(readContainer($bytes, $key, $dek, $manifest))->toBe('hello world');
});

it('round-trips plaintext spanning many chunks', function (): void {
    $key = makeKey();
    $dek = makeDek();
    $plaintext = str_repeat('0123456789', 1000); // 10,000 bytes

    [$bytes, $manifest] = writeContainer($plaintext, 777, $key, $dek);

    expect($manifest->chunkCount)->toBe((int) ceil(10_000 / 777))
        ->and(readContainer($bytes, $key, $dek, $manifest))->toBe($plaintext);
});

it('round-trips an empty plaintext as zero chunks', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('', 4096, $key, $dek);

    expect($manifest->chunkCount)->toBe(0)
        ->and($manifest->sha256)->toBe(hash('sha256', ''))
        ->and(readContainer($bytes, $key, $dek, $manifest))->toBe('');
});

it('never contains the plaintext as a byte substring in the sealed container', function (): void {
    $key = makeKey();
    $dek = makeDek();
    $marker = 'CNP-1234567890123-DO-NOT-LEAK';

    [$bytes] = writeContainer(str_repeat('padding ', 50) . $marker . str_repeat(' padding', 50), 64, $key, $dek);

    expect($bytes)->not->toContain($marker);
});

it('streams a large payload without holding the whole thing in memory', function (): void {
    $key = makeKey();
    $dek = makeDek();
    $chunkSize = 4 * 1024 * 1024;
    $totalBytes = 50 * 1024 * 1024;

    // Built on a real temp file, written in small increments, so the *fixture itself* never
    // materialises 50 MB as one in-memory string either — otherwise the peak-usage assertion
    // below would only be proving the fixture's memory use, not the writer/reader's.
    $path = tempnam(sys_get_temp_dir(), 'objectstore-large-');

    if ($path === false) {
        throw new RuntimeException('Failed creating a temp file for the large-payload fixture.');
    }

    $handle = fopen($path, 'wb');

    if ($handle === false) {
        throw new RuntimeException('Failed opening the large-payload fixture for writing.');
    }

    $block = random_bytes(65536);

    for ($written = 0; $written < $totalBytes; $written += strlen($block)) {
        fwrite($handle, $block);
    }

    fclose($handle);

    $source = fopen($path, 'rb');
    $destination = fopen('php://temp/maxmemory:2097152', 'w+b');

    if ($source === false || $destination === false) {
        throw new RuntimeException('Failed opening streams for the large-payload fixture.');
    }

    memory_reset_peak_usage();
    $before = memory_get_peak_usage(true);
    $manifest = (new StreamEnvelopeWriter($chunkSize))->write($source, $destination, $key, $dek);
    $writePeakDelta = memory_get_peak_usage(true) - $before;

    fclose($source);
    unlink($path);

    expect($manifest->sizeBytes)->toBe($totalBytes)
        ->and($manifest->chunkCount)->toBe((int) ceil($totalBytes / $chunkSize));

    rewind($destination);
    $plaintextOut = fopen('php://temp/maxmemory:2097152', 'w+b');

    if ($plaintextOut === false) {
        throw new RuntimeException('Failed opening the plaintext output stream.');
    }

    memory_reset_peak_usage();
    $before = memory_get_peak_usage(true);
    (new StreamEnvelopeReader())->read($destination, $plaintextOut, $key, $dek, $manifest);
    $readPeakDelta = memory_get_peak_usage(true) - $before;

    fclose($destination);

    // A naive whole-file implementation would hold the 50 MB plaintext, then ~50 MB of
    // ciphertext, then ~67 MB of base64 simultaneously (~150+ MB combined) on the write side
    // alone. A bound far below the 50 MB payload itself — let alone that combined figure —
    // demonstrates this is genuinely chunked rather than merely "still correct by accident."
    expect($writePeakDelta)->toBeLessThan(30 * 1024 * 1024);
    expect($readPeakDelta)->toBeLessThan(30 * 1024 * 1024);

    rewind($plaintextOut);

    $actualHash = hash_init('sha256');
    while (! feof($plaintextOut)) {
        $chunk = fread($plaintextOut, 1024 * 1024);
        if ($chunk !== false && $chunk !== '') {
            hash_update($actualHash, $chunk);
        }
    }
    fclose($plaintextOut);

    expect(hash_final($actualHash))->toBe($manifest->sha256);
})->group('slow');

it('rejects a chunk decrypted at a reordered position (AAD binds the chunk index)', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('AAAABBBBCCCC', 4, $key, $dek);
    [$header, $frames] = splitFrames($bytes);
    expect($frames)->toHaveCount(3);

    [$frames[0], $frames[1]] = [$frames[1], $frames[0]];
    $tampered = joinFrames($header, $frames);

    readContainer($tampered, $key, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects a duplicated chunk inserted into the sequence', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('AAAABBBBCCCC', 4, $key, $dek);
    [$header, $frames] = splitFrames($bytes);

    // Duplicate frame 0 into position 1, pushing the rest along — position 1 now holds a frame
    // whose embedded AAD chunk index is 0, not 1.
    $duplicated = [$frames[0], $frames[0], $frames[1], $frames[2]];
    $tampered = joinFrames($header, $duplicated);

    readContainer($tampered, $key, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects truncation of the final chunk', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('AAAABBBBCCCC', 4, $key, $dek);
    [$header, $frames] = splitFrames($bytes);

    array_pop($frames);
    $tampered = joinFrames($header, $frames);

    readContainer($tampered, $key, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects a dropped middle chunk (surviving chunks land at the wrong AAD-bound positions)', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('AAAABBBBCCCC', 4, $key, $dek);
    [$header, $frames] = splitFrames($bytes);

    unset($frames[1]);
    $tampered = joinFrames($header, array_values($frames));

    readContainer($tampered, $key, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects a corrupted frame (flipped byte breaks the AEAD tag)', function (): void {
    $key = makeKey();
    $dek = makeDek();

    [$bytes, $manifest] = writeContainer('AAAABBBBCCCC', 4, $key, $dek);
    [$header, $frames] = splitFrames($bytes);

    $frame = $frames[0];
    $middle = (int) floor(strlen($frame) / 2);
    $frame[$middle] = $frame[$middle] === 'A' ? 'B' : 'A';
    $frames[0] = $frame;
    $tampered = joinFrames($header, $frames);

    readContainer($tampered, $key, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects a wrong DEK entirely', function (): void {
    $key = makeKey();
    $dek = makeDek();
    $wrongDek = makeDek();

    [$bytes, $manifest] = writeContainer('hello world', 4096, $key, $dek);

    readContainer($bytes, $key, $wrongDek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects the same content moved to a different object key (AAD binds the key path)', function (): void {
    $dek = makeDek();
    $key = makeKey();
    $otherKey = makeKey(); // different subject AND document id

    [$bytes, $manifest] = writeContainer('hello world', 4096, $key, $dek);

    readContainer($bytes, $otherKey, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects content moved to a different key path for the *same* subject (AAD, not just the DEK, is doing the work)', function (): void {
    $dek = makeDek();
    $subjectId = (string) Str::uuid7();
    $key = ObjectKey::forDocument($subjectId, (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);
    $otherDocumentKey = ObjectKey::forDocument($subjectId, (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);

    [$bytes, $manifest] = writeContainer('hello world', 4096, $key, $dek);

    // Same subject -> same DEK in production, so if this failed to decrypt it could only be the
    // AAD (which embeds the full object key, not just the subject id) doing the rejecting.
    readContainer($bytes, $otherDocumentKey, $dek, $manifest);
})->throws(ObjectIntegrityException::class);

it('rejects a malformed container header', function (): void {
    readContainer('not a container', makeKey(), makeDek(), new ObjectManifest(hash('sha256', ''), 0, 4096, 0, 1));
})->throws(ObjectIntegrityException::class);
