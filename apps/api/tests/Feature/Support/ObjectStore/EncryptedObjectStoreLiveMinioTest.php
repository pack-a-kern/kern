<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ObjectStore\EncryptedObjectStoreLiveMinioTest;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\ObjectStore\DocumentObjectKind;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\ObjectStore\Exceptions\ObjectIntegrityException;
use Kern\Platform\ObjectStore\ObjectKey;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * Proves `EncryptedObjectStore` against a real, running MinIO (docker/... —
 * docs/handoff/001-docker-topology.md), not Flysystem's local fake.
 *
 * Skipped unless `OBJECTSTORE_TEST_ENDPOINT` and `OBJECTSTORE_TEST_BUCKET` are both set —
 * deliberately a **separate** env-var namespace from the app's own `AWS_*`/
 * `config('filesystems.disks.s3')`, not a reuse of it. `apps/api/.env` legitimately carries dev
 * `AWS_ENDPOINT=http://127.0.0.1:9000`/`AWS_BUCKET=kern-documents` for `php artisan serve`-style
 * local development; a skip-check keyed on those would read "available" in any shell that sources
 * that `.env`, including a throwaway container with no route to `127.0.0.1:9000` and no
 * credentials — which fails loudly instead of skipping cleanly. `OPENBAO_TEST_ADDR`/
 * `OPENBAO_TEST_TOKEN` in docs/handoff/005-crypto-core.md's OpenBaoLiveIntegrationTest exist for
 * the exact same reason; this file follows that precedent rather than the shortcut that broke.
 *
 * This exists for the same reason that test does: `EncryptedObjectStoreTest`'s local-fake
 * coverage cannot prove wire compatibility with a real S3-compatible service, and the raw-bucket
 * assertion below — BLUEPRINT.md §4.6's actual definition of done — is only meaningful against a
 * real bucket. See docs/handoff/012-encrypted-object-store.md "How to verify" for the exact
 * command that ran this for real.
 */
function liveMinioUnavailable(): bool
{
    return getenv('OBJECTSTORE_TEST_ENDPOINT') === false || getenv('OBJECTSTORE_TEST_BUCKET') === false;
}

/**
 * Binds the in-memory DEK service (no `tax_subjects` dependency needed for this module) and
 * points the `s3` disk at the live test bucket via `OBJECTSTORE_TEST_*`, entirely replacing
 * whatever `config('filesystems.disks.s3')` would otherwise resolve to.
 */
function configureLiveDisk(Application $app): void
{
    $app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    $endpoint = getenv('OBJECTSTORE_TEST_ENDPOINT');
    $bucket = getenv('OBJECTSTORE_TEST_BUCKET');
    $key = getenv('OBJECTSTORE_TEST_KEY');
    $secret = getenv('OBJECTSTORE_TEST_SECRET');
    $region = getenv('OBJECTSTORE_TEST_REGION');

    config(['filesystems.disks.s3' => [
        'driver' => 's3',
        'key' => $key !== false ? $key : '',
        'secret' => $secret !== false ? $secret : '',
        'region' => $region !== false ? $region : 'eu-central-1',
        'bucket' => $bucket !== false ? $bucket : '',
        'endpoint' => $endpoint !== false ? $endpoint : '',
        'use_path_style_endpoint' => true,
        'throw' => false,
        'report' => false,
    ]]);
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
        throw new RuntimeException('Expected a stored object to exist on the live MinIO bucket.');
    }

    return $value;
}

function makeKey(?string $subjectId = null): ObjectKey
{
    return ObjectKey::forDocument($subjectId ?? (string) Str::uuid7(), (string) Str::uuid7(), DocumentObjectKind::ORIGINAL);
}

it('round-trips a small document through a real MinIO bucket, byte-identical', function (): void {
    configureLiveDisk($this->app);
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();
    $content = 'a real document body, stored against a real bucket';

    $store->put($key, streamOf($content));

    try {
        expect(readAll($store->get($key)))->toBe($content);
    } finally {
        $store->delete($key);
    }
})->skip(fn (): bool => liveMinioUnavailable(), 'Set OBJECTSTORE_TEST_ENDPOINT/OBJECTSTORE_TEST_BUCKET to run against a live MinIO.');

it('never stores the plaintext marker in the raw MinIO object — M1 definition of done', function (): void {
    configureLiveDisk($this->app);
    $store = app(EncryptedObjectStore::class);
    $disk = Storage::disk('s3');
    $key = makeKey();
    $marker = 'CNP-1900101234567-STREET-NAME-42';

    $store->put($key, streamOf(str_repeat('filler content ', 500) . $marker . str_repeat(' more filler', 500)));

    try {
        $raw = requireStoredString($disk->get($key->value));
        expect($raw)->not->toContain($marker);
    } finally {
        $store->delete($key);
    }
})->skip(fn (): bool => liveMinioUnavailable(), 'Set OBJECTSTORE_TEST_ENDPOINT/OBJECTSTORE_TEST_BUCKET to run against a live MinIO.');

it('rejects content moved to a different subject\'s key path on a real bucket', function (): void {
    configureLiveDisk($this->app);
    $store = app(EncryptedObjectStore::class);
    $disk = Storage::disk('s3');
    $key = makeKey();
    $otherKey = makeKey();

    $store->put($key, streamOf('sensitive content'));

    $rawContent = requireStoredString($disk->get($key->value));
    $rawManifest = requireStoredString($disk->get($key->manifestKey()));
    $disk->put($otherKey->value, $rawContent);
    $disk->put($otherKey->manifestKey(), $rawManifest);

    try {
        $failed = false;

        try {
            $store->get($otherKey);
        } catch (ObjectIntegrityException) {
            $failed = true;
        }

        expect($failed)->toBeTrue();
    } finally {
        $store->delete($key);
        $store->delete($otherKey);
    }
})->skip(fn (): bool => liveMinioUnavailable(), 'Set OBJECTSTORE_TEST_ENDPOINT/OBJECTSTORE_TEST_BUCKET to run against a live MinIO.');

it('round-trips a 50 MB payload through a real MinIO bucket', function (): void {
    configureLiveDisk($this->app);
    $store = app(EncryptedObjectStore::class);
    $key = makeKey();

    $path = tempnam(sys_get_temp_dir(), 'objectstore-live-large-');

    if ($path === false) {
        throw new RuntimeException('Failed creating a temp file.');
    }

    $handle = fopen($path, 'wb');

    if ($handle === false) {
        throw new RuntimeException('Failed opening the temp file for writing.');
    }

    $block = random_bytes(65536);
    $total = 50 * 1024 * 1024;

    for ($written = 0; $written < $total; $written += strlen($block)) {
        fwrite($handle, $block);
    }

    fclose($handle);

    $expectedHash = hash_file('sha256', $path);
    $source = fopen($path, 'rb');

    if ($source === false) {
        throw new RuntimeException('Failed opening the temp file for reading.');
    }

    $manifest = $store->put($key, $source);
    fclose($source);
    unlink($path);

    try {
        expect($manifest->sizeBytes)->toBe($total);

        $retrieved = $store->get($key);
        $actualHash = hash_init('sha256');

        while (! feof($retrieved)) {
            $chunk = fread($retrieved, 1024 * 1024);

            if ($chunk !== false && $chunk !== '') {
                hash_update($actualHash, $chunk);
            }
        }

        fclose($retrieved);

        expect(hash_final($actualHash))->toBe($expectedHash);
    } finally {
        $store->delete($key);
    }
})->skip(fn (): bool => liveMinioUnavailable(), 'Set OBJECTSTORE_TEST_ENDPOINT/OBJECTSTORE_TEST_BUCKET to run against a live MinIO.')->group('slow');
