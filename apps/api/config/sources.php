<?php

declare(strict_types=1);

// Official source registry — BLUEPRINT.md §8.2, docs/AGENTS.md §1.1 ("never invent an endpoint").
//
// This file, not a database table, is the authoritative allowlist: BLUEPRINT.md §8.2 requires the
// crawler to "not accept a redirect off the allowlist without review" — putting `allowed_hosts`
// here means changing it goes through a PR/code review, not an ad-hoc DB edit. The corresponding
// `ext_official_sources` table (App\Domain\TaxKnowledge\Models\OfficialSource) exists only so other
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
            // Which source is preferred when two publish the same data: 1 before 2. Every source is the
            // only one for its data today, so each is 1; a second rate provider would be 2.
            'priority' => 1,
        ],

        'ANAF' => [
            'trust_tier' => 'B',
            'base_url' => 'https://www.anaf.ro',
            'allowed_hosts' => ['anaf.ro', 'www.anaf.ro', 'static.anaf.ro'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
            'priority' => 1,
        ],

        'MF' => [
            'trust_tier' => 'C',
            'base_url' => 'https://mfinante.gov.ro',
            'allowed_hosts' => ['mfinante.gov.ro'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
            'priority' => 1,
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
            // The TLS key-exchange groups offered to BNR. OpenSSL 3.5 — what PHP runs on in the app
            // image — adds a post-quantum hybrid group by default, which makes the opening handshake
            // message larger than one packet, and BNR's firewall resets the connection: verified
            // 2026-09-15, the daily feed timed out with the default and answered in 0.16s with these.
            // Other sources accept the default, so they declare none.
            'tls_curves' => env('BNR_TLS_CURVES', 'X25519:P-256:P-384'),
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
            'priority' => 1,
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

        // The European Central Bank's list of financial institutions — every bank in the EU, daily.
        // Read by App\Domain\Banks\Actions\ImportBanks. Verified 2026-09-15: the list page and the
        // file it links to are both served from this host, without a redirect.
        'ECB' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.ecb.europa.eu',
            'allowed_hosts' => ['www.ecb.europa.eu'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
            'priority' => 1,
            'endpoints' => [
                // The page, never the file: the file's name carries its date and is replaced daily.
                'institution_list_page' => env(
                    'ECB_INSTITUTION_LIST_PAGE_URL',
                    'https://www.ecb.europa.eu/stats/financial_corporations/list_of_financial_institutions/html/daily_list-MID.en.html',
                ),
            ],
        ],

        // The Global Legal Entity Identifier Foundation: the BIC-to-LEI file it publishes monthly with
        // SWIFT, and ISO 20275, the list of legal forms (S.A., N.V., EAD) a bank's name is stripped
        // of. Verified 2026-09-15: the BIC archives download from mapping.gleif.org without a
        // redirect; the legal forms file redirects once, within www.gleif.org.
        'GLEIF' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.gleif.org',
            'allowed_hosts' => ['www.gleif.org', 'mapping.gleif.org'],
            'user_agent' => 'TaxOS-RO-SourceCrawler/1.0 (+https://taxos.ro; contact: compliance@taxos.ro)',
            'enabled' => true,
            'priority' => 1,
            'endpoints' => [
                'bic_lei_page' => env(
                    'GLEIF_BIC_LEI_PAGE_URL',
                    'https://www.gleif.org/en/lei-data/lei-mapping/download-bic-to-lei-relationship-files',
                ),
                'legal_forms_page' => env(
                    'GLEIF_LEGAL_FORMS_PAGE_URL',
                    'https://www.gleif.org/en/about-lei/code-lists/iso-20275-entity-legal-forms-code-list',
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
    // string (App\Domain\Fx\ValueObjects\DecimalRate). `decimal_scale` is the ext_fx_rates/
    // ext_fx_period_averages storage precision; `verification_tolerance` is the maximum absolute
    // difference between a calculated period average and BNR's own published value before
    // App\Domain\Fx\Enums\VerificationStatus::MISMATCH is recorded instead of VERIFIED.
    'fx' => [
        'decimal_scale' => (int) env('FX_DECIMAL_SCALE', 6),
        'verification_tolerance' => env('FX_VERIFICATION_TOLERANCE', '0.0001'),
    ],

];
