<?php

declare(strict_types=1);

// Official source registry — BLUEPRINT.md §8.2, docs/AGENTS.md §1.1 ("never invent an endpoint").
//
// This file, not a database table, is the authoritative allowlist: BLUEPRINT.md §8.2 requires the
// crawler to "not accept a redirect off the allowlist without review" — putting `allowed_hosts`
// here means changing it goes through a PR/code review, not an ad-hoc DB edit. The corresponding
// `official_sources` table (App\Domain\TaxKnowledge\Models\OfficialSource) exists only so other
// tables can carry a foreign key; App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository
// mirrors this config into that table on first use of each code, and re-syncs the mirrored
// columns (trust_tier/base_url/enabled) every time — this file remains the single source of
// truth, the DB row is a cache for joins, never edited by hand.
return [

    'entries' => [

        'LEGISLATIE' => [
            'trust_tier' => 'A',
            'base_url' => 'https://legislatie.just.ro',
            'allowed_hosts' => ['legislatie.just.ro'],
            // Verified 2026-09-08 (docs/reference/anaf-spv-integration.md): this host returns
            // 403 to a plain HTTP client and 200 once a realistic browser User-Agent is sent.
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'enabled' => true,
        ],

        'ANAF' => [
            'trust_tier' => 'B',
            'base_url' => 'https://www.anaf.ro',
            'allowed_hosts' => ['anaf.ro', 'www.anaf.ro', 'static.anaf.ro'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
        ],

        'MF' => [
            'trust_tier' => 'C',
            'base_url' => 'https://mfinante.gov.ro',
            'allowed_hosts' => ['mfinante.gov.ro'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
        ],

        'BNR' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.bnr.ro',
            // Real, confirmed-reachable hostnames (docs/reference/bnr-fx-endpoints.md,
            // investigated 2026-09-08) — the allowlist itself is not in question, only the
            // path. Do not add a host here that hasn't been reached from this environment.
            // curs.bnr.ro is BNR's dedicated XML server, announced on the official XML page as
            // "NOU: Server dedicat fişierelor XML". Verified reachable and serving real data on
            // 2026-09-08 — see docs/reference/bnr-fx-endpoints.md.
            'allowed_hosts' => ['bnr.ro', 'www.bnr.ro', 'www2.bnr.ro', 'curs.bnr.ro'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
            'endpoints' => [
                // RESOLVED 2026-09-08. BNR moved its XML feeds to a dedicated server,
                // curs.bnr.ro. The old www.bnr.ro paths 302-redirect to the homepage, and the
                // links are only present in the JavaScript-rendered DOM of the official XML page,
                // which is why plain HTTP discovery failed — see docs/reference/bnr-fx-endpoints.md.
                //
                // Both URLs below were fetched and validated from this environment:
                // the daily feed returned 200 text/xml with 37 rates for 2026-09-07, and the 2025
                // archive returned 248 banking days (2025-01-03 .. 2025-12-31).
                //
                // Still overridable by env so a future relocation needs no code change (§7.1
                // requires the crawler to tolerate exactly this kind of move).
                'daily_xml' => env('BNR_DAILY_XML_URL', 'https://curs.bnr.ro/nbrfxrates.xml'),
                'yearly_xml_template' => env(
                    'BNR_YEARLY_XML_URL_TEMPLATE',
                    'https://curs.bnr.ro/files/xml/years/nbrfxrates{year}.xml',
                ),
            ],
        ],

    ],

    // App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher (BLUEPRINT.md §7.2/§14.2 "size/mime
    // sanity", §4.1 SSRF control).
    'fetch' => [
        'max_response_bytes' => (int) env('SOURCES_FETCH_MAX_BYTES', 10 * 1024 * 1024),
        'max_redirects' => (int) env('SOURCES_FETCH_MAX_REDIRECTS', 5),
        'timeout_seconds' => (int) env('SOURCES_FETCH_TIMEOUT_SECONDS', 15),
    ],

    // App\Domain\TaxKnowledge\Storage\RawSourceObjectStore — immutable raw storage (BLUEPRINT.md
    // §9.1). Deliberately not the subject-scoped App\Support\ObjectStore\EncryptedObjectStore —
    // see docs/handoff/014-source-registry-bnr.md "Key decisions" for why an official source
    // document (no tax_subject_id, public, not PII) cannot be expressed by that module's
    // ObjectKey::forDocument() without a scope-violating change to app/Support/ObjectStore.
    'storage' => [
        'disk' => env('SOURCES_STORAGE_DISK', 's3'),
        'prefix' => env('SOURCES_STORAGE_PREFIX', 'sources'),
    ],

    // App\Domain\Fx — BLUEPRINT.md §7.4/§7.5. No floats anywhere: every rate is a BCMath decimal
    // string (App\Domain\Fx\ValueObjects\DecimalRate). `decimal_scale` is the fx_rates/
    // fx_period_averages storage precision; `verification_tolerance` is the maximum absolute
    // difference between a calculated period average and BNR's own published value before
    // App\Domain\Fx\Enums\VerificationStatus::MISMATCH is recorded instead of VERIFIED.
    'fx' => [
        'decimal_scale' => (int) env('FX_DECIMAL_SCALE', 6),
        'verification_tolerance' => env('FX_VERIFICATION_TOLERANCE', '0.0001'),
    ],

];
