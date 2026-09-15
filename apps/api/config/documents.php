<?php

declare(strict_types=1);

// Milestone 7 document pipeline — BLUEPRINT.md §14, docs/handoff/022-document-pipeline.md.
return [

    // A Flysystem disk (config/filesystems.php) physically separate from `objectstore.disk` —
    // App\Domain\Documents\Quarantine\QuarantineObjectStore's whole guarantee depends on this
    // being a different bucket the rest of the app never reads from.
    'quarantine_disk' => env('DOCUMENTS_QUARANTINE_DISK', 'quarantine'),

    // File-security limits (BLUEPRINT.md §14.2). Engineering defaults, not fiscal values — no
    // source_section_id applies.
    'max_pdf_bytes' => (int) env('DOCUMENTS_MAX_PDF_BYTES', 25 * 1024 * 1024),
    'max_image_bytes' => (int) env('DOCUMENTS_MAX_IMAGE_BYTES', 15 * 1024 * 1024),
    // A spreadsheet is text, so it is bounded by what a person plausibly exports rather than by
    // what a scanner produces: two years of one account's transactions is well under a megabyte,
    // and a text file in the tens of megabytes is not a statement anybody meant to upload.
    // How many pages a document may have before it is refused unread. Counting is milliseconds;
    // a provider reading a 260-page statement is minutes, so this is what makes the rest affordable.
    'max_pages' => (int) env('DOCUMENTS_MAX_PAGES', 10),

    'max_text_bytes' => (int) env('DOCUMENTS_MAX_TEXT_BYTES', 10 * 1024 * 1024),

    'max_image_pixels' => (int) env('DOCUMENTS_MAX_IMAGE_PIXELS', 40_000_000),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'connect_timeout_seconds' => (float) env('CLAMAV_CONNECT_TIMEOUT_SECONDS', 5),
        'stream_timeout_seconds' => (float) env('CLAMAV_STREAM_TIMEOUT_SECONDS', 30),
        'chunk_size' => (int) env('CLAMAV_CHUNK_SIZE', 65536),
    ],

    // The native-PDF-text-extraction HTTP endpoint (docs/handoff/024-extraction-endpoint.md).
    // App\Domain\Documents\Extraction\HttpTextExtractionGateway's own docblock documents the
    // exact, verified contract.
    'text_extraction' => [
        'base_url' => env('DOCUMENTS_TEXT_EXTRACTION_URL', 'http://python-api:8000'),
        'timeout_seconds' => (int) env('DOCUMENTS_TEXT_EXTRACTION_TIMEOUT_SECONDS', 30),

        // Shared secret sent as `Authorization: Bearer <token>` — must equal the Python
        // service's own `INTERNAL_SERVICE_AUTH_TOKEN` (compose.yaml/.env.example wire the same
        // value into both containers). Empty by default so a missing value fails closed
        // (HttpTextExtractionGateway::authenticationNotConfigured()) rather than silently
        // sending an unauthenticated request — BLUEPRINT.md §4.10, never logged.
        'auth_token' => env('INTERNAL_SERVICE_AUTH_TOKEN', ''),
    ],

    'rate_limits' => [
        'upload_per_subject' => [
            'max_attempts' => (int) env('DOCUMENTS_UPLOAD_RATE_LIMIT_MAX_ATTEMPTS', 30),
            'decay_seconds' => (int) env('DOCUMENTS_UPLOAD_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
    ],
];
