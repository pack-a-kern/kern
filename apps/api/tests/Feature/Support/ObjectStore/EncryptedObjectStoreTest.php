<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ObjectStore\EncryptedObjectStoreTest;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kern\Documents\ObjectStore\DocumentObjectKind;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\Exceptions\ObjectIntegrityException;
use Kern\Documents\ObjectStore\Exceptions\ObjectNotFoundException;
use Kern\Documents\ObjectStore\ObjectKey;
use Kern\Platform\KeyManagement\SubjectDekService;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * Exercises the full `EncryptedObjectStore` (real service-provider wiring, real Flysystem calls)
 * against `Storage::fake('s3')` — a real local Flysystem adapter under the same disk name the
 * app uses in every environment, per Laravel's own testing helper. Deliberately runs on every
 * `vendor/bin/pest` invocation (no MinIO required) so this coverage isn't gated on a live
 * service; docs/handoff/012-encrypted-object-store.md's live-MinIO test proves the same
 * behaviour survives a real S3-compatible round trip, most importantly the raw-bucket assertion
 * this file's local version of cannot fully substitute for (see that test's docblock).
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    Storage::fake('s3');
});

function makeKey(?string $subjectId = null, DocumentObjectKind $kind = DocumentObjectKind::ORIGINAL): ObjectKey
{
    return ObjectKey::forDocument($subjectId ?? (string) Str::uuid7(), (string) Str::uuid7(), $kind);
}

/** @return resource */
function streamOf(string $content)
{
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Failed opening a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

/** @param resource $resource */
function readAll($resource): string
{
    $out = stream_get_contents($resource);
    fclose($resource);

    if ($out === false) {
        throw new RuntimeException('Failed reading a stream to completion.');
    }

    return $out;
}

function requireStoredString(?string $value): string
{
    if ($value === null) {
        throw new RuntimeException('Expected a stored object to exist on the fake disk.');
    }

    return $value;
}

it('round-trips content byte-identical', function (): void {
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $content = 'contract text: ' . str_repeat('lorem ipsum ', 40);

    $manifest = $store->put($key, streamOf($content));

    expect(readAll($store->get($key)))->toBe($content)
        ->and($manifest->sha256)->toBe(hash('sha256', $content))
        ->and($manifest->sizeBytes)->toBe(strlen($content));
});

it('round-trips multi-chunk content through the full store with a small chunk size', function (): void {
    config(['objectstore.chunk_size' => 5]);
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $content = str_repeat('0123456789', 50); // 500 bytes, 100 five-byte chunks

    $store->put($key, streamOf($content));

    expect(readAll($store->get($key)))->toBe($content);
});

it('never stores the plaintext marker in the raw disk object', function (): void {
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $marker = 'CNP-1900101234567-STREET-42';

    $store->put($key, streamOf(str_repeat('filler ', 100) . $marker . str_repeat(' filler', 100)));

    $raw = Storage::disk('s3')->get($key->value);

    expect($raw)->not->toBeNull()->not->toContain($marker);
});

it('rejects content moved to a different subject\'s key path', function (): void {
    $store = app(EncryptedObjectStore::class);
    $disk = Storage::disk('s3');
    $key = makeKey();
    $otherKey = makeKey(); // fresh random subject id and document id

    $store->put($key, streamOf('sensitive content'));

    $disk->put($otherKey->value, requireStoredString($disk->get($key->value)));
    $disk->put($otherKey->manifestKey(), requireStoredString($disk->get($key->manifestKey())));

    $store->get($otherKey);
})->throws(ObjectIntegrityException::class);

it('rejects content moved to a different document under the same subject (AAD, not just the DEK, enforces this)', function (): void {
    $store = app(EncryptedObjectStore::class);
    $disk = Storage::disk('s3');
    $subjectId = (string) Str::uuid7();
    $key = makeKey($subjectId);
    $otherKey = makeKey($subjectId); // same subject -> same DEK, different document id

    $store->put($key, streamOf('document A content'));

    $disk->put($otherKey->value, requireStoredString($disk->get($key->value)));
    $disk->put($otherKey->manifestKey(), requireStoredString($disk->get($key->manifestKey())));

    $store->get($otherKey);
})->throws(ObjectIntegrityException::class);

it('rejects a manifest that fails authentication', function (): void {
    $store = app(EncryptedObjectStore::class);
    $disk = Storage::disk('s3');
    $key = makeKey();

    $store->put($key, streamOf('some content'));

    $rawManifest = requireStoredString($disk->get($key->manifestKey()));
    $tampered = substr($rawManifest, 0, -1) . ($rawManifest[-1] === 'A' ? 'B' : 'A');
    $disk->put($key->manifestKey(), $tampered);

    $store->get($key);
})->throws(ObjectIntegrityException::class);

it('rejects tampered content chunks end-to-end through the store', function (): void {
    config(['objectstore.chunk_size' => 4]);
    $store = app(EncryptedObjectStore::class);
    $disk = Storage::disk('s3');
    $key = makeKey();

    $store->put($key, streamOf('AAAABBBBCCCC'));

    $raw = requireStoredString($disk->get($key->value));
    $position = strlen($raw) - 5; // well inside the last frame's sealed payload
    $raw[$position] = $raw[$position] === 'A' ? 'B' : 'A';
    $disk->put($key->value, $raw);

    $store->get($key);
})->throws(ObjectIntegrityException::class);

it('throws when content is missing', function (): void {
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $store->put($key, streamOf('x'));
    Storage::disk('s3')->delete($key->value);

    $store->get($key);
})->throws(ObjectNotFoundException::class);

it('throws when the manifest is missing', function (): void {
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $store->put($key, streamOf('x'));
    Storage::disk('s3')->delete($key->manifestKey());

    $store->get($key);
})->throws(ObjectNotFoundException::class);

it('throws not-found for a key that was never stored', function (): void {
    app(EncryptedObjectStore::class)->get(makeKey());
})->throws(ObjectNotFoundException::class);

it('delete removes both the content object and its manifest', function (): void {
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $store->put($key, streamOf('x'));

    $store->delete($key);

    expect(Storage::disk('s3')->exists($key->value))->toBeFalse()
        ->and(Storage::disk('s3')->exists($key->manifestKey()))->toBeFalse();
});
