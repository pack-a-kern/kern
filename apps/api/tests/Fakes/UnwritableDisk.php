<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * A Flysystem disk that accepts reads but refuses every write, exactly the way Laravel's S3 disk
 * does when the bucket does not exist: `writeStream()`/`put()` return `false` rather than throwing,
 * because `config/filesystems.php` sets `'throw' => false` on both S3 disks.
 *
 * That silent `false` is what made a missing `kern-quarantine` bucket surface as
 * `EncryptedObjectStore`'s own "Failed writing object …" `RuntimeException` — the exact production
 * failure this fake reproduces, so the rollback it triggers can be tested without a broken MinIO.
 *
 * Extends `FilesystemAdapter` rather than implementing the `Filesystem` contract by hand: the
 * contract has a few dozen methods, and a test double that had to restate all of them would be
 * noise around the one method that matters.
 */
final class UnwritableDisk extends FilesystemAdapter
{
    public function __construct()
    {
        $adapter = new LocalFilesystemAdapter(sys_get_temp_dir() . '/kern-unwritable-disk');

        parent::__construct(new Flysystem($adapter), $adapter);
    }

    /**
     * @param  string  $path
     * @param  resource  $resource
     * @param  array<string, mixed>  $options
     */
    public function writeStream($path, $resource, array $options = []): bool
    {
        return false;
    }

    /**
     * @param  string  $path
     * @param  \Psr\Http\Message\StreamInterface|\Illuminate\Http\File|\Illuminate\Http\UploadedFile|string|resource  $contents
     */
    public function put($path, $contents, mixed $options = []): bool
    {
        return false;
    }
}
