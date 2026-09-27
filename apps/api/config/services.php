<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Conventional location for service addresses and credentials.
    |
    */

    /*
     * The processing service (`apps/processing`): one Python server behind three features — reading
     * text out of PDFs, embedding legislation, and reading bank statement files.
     *
     * Described once. Each feature used to carry its own copy of this address under its own env var,
     * so moving the service meant three edits, and missing one broke that feature alone while the
     * health page — which probed only the documents copy — reported everything up. Each feature
     * keeps its own timeout, because reading a PDF and embedding a law genuinely take different times.
     */
    'processing' => [
        'base_url' => env('PROCESSING_URL'),

        // Sent as `Authorization: Bearer <token>`, and must equal the Python service's own
        // `INTERNAL_SERVICE_AUTH_TOKEN` — compose wires the same value into both containers. Empty by
        // default so a missing value fails closed rather than sending an unauthenticated request
        // (BLUEPRINT.md §4.10). Never logged.
        'auth_token' => env('INTERNAL_SERVICE_AUTH_TOKEN', ''),
    ],

];
