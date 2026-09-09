<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Storage;

final readonly class StoredRawObject
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $sizeBytes,
    ) {
    }
}
