<?php

declare(strict_types=1);

// Milestone 1 encrypted object store — BLUEPRINT.md §4.6/§4.9, docs/handoff/012-encrypted-object-store.md.
return [

    // Which Laravel filesystem disk (config/filesystems.php) encrypted objects are written to.
    // Defaults to the 's3' disk, already configured to point at MinIO in dev and a real
    // S3-compatible bucket in production (BLUEPRINT.md §1.1/§3.1).
    'disk' => env('OBJECTSTORE_DISK', 's3'),

    // Plaintext bytes read/sealed per chunk when storing, and per frame when retrieving
    // (BLUEPRINT.md §4.6/§14.2 — a document must never be loaded wholly into memory to encrypt or
    // decrypt it). 4 MiB keeps peak PHP memory bounded regardless of document size.
    'chunk_size' => (int) env('OBJECTSTORE_CHUNK_SIZE', 4 * 1024 * 1024),

];
