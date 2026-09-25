<?php

declare(strict_types=1);

namespace Kern\Legislation\Storage;

final readonly class StoredRawObject
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $sizeBytes,
    ) {
    }
}
