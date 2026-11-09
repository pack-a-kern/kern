<?php

declare(strict_types=1);

// Official source registry — BLUEPRINT.md §8.2, docs/AGENTS.md §1.1 ("never invent an endpoint").
//
// This file, not a database table, is the authoritative allowlist: BLUEPRINT.md §8.2 requires the
// crawler to "not accept a redirect off the allowlist without review" — putting `allowed_hosts`
// here means changing it goes through a PR/code review, not an ad-hoc DB edit. The corresponding
// `ext_official_sources` table (Kern\Legislation\Models\OfficialSource) exists only so other
// tables can carry a foreign key; Kern\Legislation\Repositories\OfficialSourceRepository
// mirrors this config into that table on first use of each code, and re-syncs the mirrored
// columns (trust_tier/base_url/enabled) every time — this file remains the single source of
// truth, the DB row is a cache for joins, never edited by hand.

return [

    /*
     * How the crawler names itself to every source that takes a client by its own name — each entry
     * below with no `user_agent` of its own. Composed by `OfficialSourceRegistry` as
     * "<name> (contact: <contact>)", so the two are set apart, on the settings screen as here.
     *
     * The contact is the operator's own — an address the source can write to about this installation's
     * traffic — so there is no default: unset, the crawler is named without one.
     */
    'crawler' => [
        // `?:`, not a default argument: an empty variable is a line someone left blank, which means the
        // default, not a request sent with no name.
        'name' => env('SOURCES_CRAWLER_NAME') ?: 'Kern-RO-SourceCrawler/1.0',
        'contact' => env('SOURCES_CRAWLER_CONTACT', ''),
    ],

    'entries' => [

        'LEGISLATIE' => [
            'trust_tier' => 'A',
            'base_url' => 'https://legislatie.just.ro',
            'allowed_hosts' => ['legislatie.just.ro'],
            // Verified 2026-09-08 (docs/reference/anaf-spv-integration.md): this host returns
            // 403 to a plain HTTP client and 200 once a realistic browser User-Agent is sent. Only what
            // this installation's own requests send; the browser worker's Chromium names itself.
            'user_agent' => env('LEGISLATIE_USER_AGENT')
                ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            // How the portal is asked, each on its own and never one in place of the other when it fails:
            // a search (the screen's, and the import's own look-up of the act) through the SOAP service or
            // the site's search page in a browser; a law's pages downloaded directly or opened in a
            // browser. Both end in the same acts and the same HTML (`Kern\Legislation\Legislatie\Portal`).
            'search' => env('LEGISLATIE_SEARCH', 'soap'),
            'pages' => env('LEGISLATIE_PAGES', 'direct'),
            // What the browser worker is sent for either, and how the site's search page is read.
            'playbook' => 'playbooks.legislatie',
            // Results to a page, which is also how a full page is told from the last. The site's search page
            // lists as many as its `rezultatePerPagina` code asks — the playbook's `page_size_value`, which
            // is fifty, so this must say fifty too. The SOAP service answers ten whatever it is asked for
            // (checked 2026-11-09, asked for fifty).
            'search_page_size' => (int) env('LEGISLATIE_SEARCH_PAGE_SIZE', 50),
            'soap_page_size' => (int) env('LEGISLATIE_SOAP_PAGE_SIZE', 10),
            // Pages read before giving up, by either way and by a queued search alike: an act number is
            // shared by a handful of acts a year, and a title search is read to this many pages at most.
            'search_max_pages' => (int) env('LEGISLATIE_SEARCH_MAX_PAGES', 20),
            // How long a search read all at once (`PortalSearch::all()` — the import's look-up, a console
            // command) may keep reading pages: past it, no further page is asked and the acts already
            // found are the answer. A queued search has no such limit; each of its pages has the job's.
            'search_deadline_seconds' => (int) env('LEGISLATIE_SEARCH_DEADLINE_SECONDS', 90),
            // How many times a search page is asked again when it fails — the site's search page answering
            // its error page, the service answering HTTP 500 — and the pause between: both answered the
            // same request either way minutes apart (2026-11-07, 2026-11-09), and asking again brings it.
            'search_refused_retries' => (int) env('LEGISLATIE_SEARCH_REFUSED_RETRIES', 4),
            'search_refused_pause_ms' => (int) env('LEGISLATIE_SEARCH_REFUSED_PAUSE_MS', 1500),
            'enabled' => true,
            // Which source is preferred when two publish the same data: 1 before 2. Every source is the
            // only one for its data today, so each is 1; a second rate provider would be 2.
            'priority' => 1,
            // Longer than the shared ceiling: one consolidation of the Codul fiscal is ~5 MB, and a
            // search of the web service answers with every matching act's full text.
            'timeout_seconds' => (int) env('LEGISLATIE_FETCH_TIMEOUT_SECONDS', 300),
            'endpoints' => [
                // The portal's SOAP service, which finds an act by type, number and year. No
                // registration; a token is asked for on each run. Verified 2026-09-22.
                'soap' => env('LEGISLATIE_SOAP_URL', 'https://legislatie.just.ro/apiws/FreeWebService.svc/SOAP'),
            ],
        ],

        'ANAF' => [
            'trust_tier' => 'B',
            'base_url' => 'https://www.anaf.ro',
            'allowed_hosts' => ['anaf.ro', 'www.anaf.ro', 'static.anaf.ro'],
            'enabled' => true,
            'priority' => 1,
        ],

        'MF' => [
            'trust_tier' => 'C',
            'base_url' => 'https://mfinante.gov.ro',
            'allowed_hosts' => ['mfinante.gov.ro'],
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
        // Read by Kern\TaxRomania\Banks\Actions\ImportBanks. Verified 2026-09-15: the list page and the
        // file it links to are both served from this host, without a redirect.
        'ECB' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.ecb.europa.eu',
            'allowed_hosts' => ['www.ecb.europa.eu'],
            'enabled' => true,
            'priority' => 1,
            'endpoints' => [
                // The page, never the file: the file's name carries its date and is replaced daily.
                'institution_list_page' => env(
                    'ECB_INSTITUTION_LIST_PAGE_URL',
                    'https://www.ecb.europa.eu/stats/financial_corporations/list_of_financial_institutions/html/daily_list-MID.en.html',
                ),
                // Every day's list stays downloadable after the page stops linking it — back to 2017,
                // checked 2026-09-21 — which is what the backfill reads banks that have since closed
                // or merged from. `{date}` is the list's date as YYMMDD; a day with no list is a 404.
                'institution_list_archive_template' => env(
                    'ECB_INSTITUTION_LIST_ARCHIVE_URL_TEMPLATE',
                    'https://www.ecb.europa.eu/stats/money/mfi/general/html/dla/mfi_MID/mfi_csv_{date}.csv.gz',
                ),
            ],
        ],

        // The Global Legal Entity Identifier Foundation: the BIC-to-LEI file it publishes monthly with
        // SWIFT, ISO 20275, the list of legal forms (S.A., N.V., EAD) a bank's name is stripped of,
        // and its API's ownership records — which group a bank belongs to. Verified 2026-09-15: the
        // BIC archives download from mapping.gleif.org without a redirect; the legal forms file
        // redirects once, within www.gleif.org. Verified 2026-11-05: api.gleif.org answers JSON with
        // no key, and 404 for an entity that reports no parent.
        'GLEIF' => [
            'trust_tier' => 'D',
            'base_url' => 'https://www.gleif.org',
            'allowed_hosts' => ['www.gleif.org', 'mapping.gleif.org', 'api.gleif.org'],
            'enabled' => true,
            'priority' => 1,
            // The language its pages are served under, given to a link on its own host that leaves it
            // out. Seen on 2026-11-07: the legal-forms page lives at /en/…, yet links its CSV as /lei-data/…,
            // and that address redirected to a copy no longer there — a 404, or redirects until the limit
            // — while the same path under /en/ answered the file. Empty, links are followed as written.
            'link_path_prefix' => '/en',
            'endpoints' => [
                'bic_lei_page' => env(
                    'GLEIF_BIC_LEI_PAGE_URL',
                    'https://www.gleif.org/en/lei-data/lei-mapping/download-bic-to-lei-relationship-files',
                ),
                'legal_forms_page' => env(
                    'GLEIF_LEGAL_FORMS_PAGE_URL',
                    'https://www.gleif.org/en/about-lei/code-lists/iso-20275-entity-legal-forms-code-list',
                ),
                // `{lei}` is the bank's. The answer is the record of the top of its group.
                'ultimate_parent_template' => env(
                    'GLEIF_ULTIMATE_PARENT_URL_TEMPLATE',
                    'https://api.gleif.org/api/v1/lei-records/{lei}/ultimate-parent',
                ),
            ],
        ],

    ],

    // Kern\Legislation\Fetching\AllowlistedHttpFetcher (BLUEPRINT.md §7.2/§14.2 "size/mime
    // sanity", §4.1 SSRF control).
    'fetch' => [
        'max_response_bytes' => (int) env('SOURCES_FETCH_MAX_BYTES', 10 * 1024 * 1024),
        'max_redirects' => (int) env('SOURCES_FETCH_MAX_REDIRECTS', 5),
        'timeout_seconds' => (int) env('SOURCES_FETCH_TIMEOUT_SECONDS', 15),
    ],

    // Kern\Legislation\Storage\RawSourceObjectStore — immutable raw storage (BLUEPRINT.md
    // §9.1). Deliberately not the subject-scoped Kern\Documents\ObjectStore\EncryptedObjectStore —
    // see docs/handoff/014-source-registry-bnr.md "Key decisions" for why an official source
    // document (no tenant, public, not PII) cannot be expressed by that module's
    // ObjectKey::forDocument() without a scope-violating change to Kern\Documents\ObjectStore.
    'storage' => [
        'disk' => env('SOURCES_STORAGE_DISK'),
        'prefix' => env('SOURCES_STORAGE_PREFIX', 'sources'),
    ],

    // Kern\TaxRomania\Fx — BLUEPRINT.md §7.4/§7.5. No floats anywhere: every rate is a BCMath decimal
    // string (Kern\TaxRomania\Fx\ValueObjects\DecimalRate). `decimal_scale` is the ext_fx_rates/
    // ext_fx_period_averages storage precision; `verification_tolerance` is the maximum absolute
    // difference between a calculated period average and BNR's own published value before
    // Kern\TaxRomania\Fx\Enums\VerificationStatus::MISMATCH is recorded instead of VERIFIED.
    // `currencies` is the shorter list the forms offer, not what is imported: every currency BNR
    // publishes is stored either way. Comma-separated because a settings row is one string; RON is
    // added back by Kern\Platform\Settings\Enums\SettingType::decodeCurrencyList() whether or not
    // it is named here.
    'fx' => [
        'decimal_scale' => (int) env('FX_DECIMAL_SCALE', 6),
        'verification_tolerance' => env('FX_VERIFICATION_TOLERANCE', '0.0001'),
        'currencies' => env('FX_CURRENCIES', 'RON,EUR,USD,GBP,CHF'),
    ],

];
