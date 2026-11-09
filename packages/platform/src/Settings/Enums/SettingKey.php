<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

/**
 * The closed set of system-wide settings this module manages. Deliberately **not** an open,
 * arbitrary-string key/value store (`docs/CODE-QUALITY.md` §2 — no speculative generality): every
 * valid key is named here, so a caller can never write to a key nothing reads, and
 * `Kern\Platform\Settings\Repositories\SettingsRepository` can decide, per key, whether the value is
 * encrypted (`isSecret()`) without trusting caller input.
 *
 * Each key names the `config()` path it overrides. Unset, the effective value is whatever that
 * config already resolves to (usually an env var, else a code default), so adding a key here
 * changes nothing until somebody edits it.
 */
enum SettingKey: string
{
    // AI document extraction (BLUEPRINT.md §14, ADR 0007).
    case AI_ENABLED = 'ai.enabled';
    case AI_PROVIDER = 'ai.provider';
    case AI_BASE_URL = 'ai.base_url';
    case AI_MODEL = 'ai.model';
    case AI_API_KEY = 'ai.api_key';

    /**
     * How long the model's reply may be.
     *
     * Editable because it is a property of the *model*, not of the deployment: a reasoning model
     * spends this budget thinking before it answers and needs thousands, an instruct model answers
     * directly and needs far fewer — and both are chosen from this same screen. Pairing a new model
     * with a cap that suits it should not need a deploy.
     */
    case AI_EXTRACTION_MAX_TOKENS = 'ai.extraction_max_tokens';

    /**
     * How long to wait for the model's reply.
     *
     * Editable for the same reason as the cap, and needed more often: reading a long statement on a
     * self-hosted model takes minutes, and a ceiling that suits a receipt reports a working model as
     * unreachable. Distinct from `EXTRACTION_TIMEOUT_SECONDS`, which bounds the local PDF text
     * service — that one is fast and unrelated.
     */
    case AI_EXTRACTION_TIMEOUT_SECONDS = 'ai.extraction_timeout_seconds';

    /**
     * Whether this provider can clean up a photographed document before it is read.
     *
     * A capability of the endpoint, not a preference: the `docscanner` extension is something a
     * gateway either implements or does not, and OpenAI does not. Off means the upload screen never
     * offers it, rather than offering something that fails.
     */
    case AI_SUPPORTS_ENHANCEMENT = 'ai.supports_enhancement';

    /**
     * Whether this provider can turn a PDF statement into CSV before it is read.
     *
     * A capability of the endpoint, like enhancement: the `docling` extension is something a gateway
     * implements or does not. Off means a statement PDF is read here, as it always was.
     */
    case AI_SUPPORTS_CONVERSION = 'ai.supports_conversion';

    // Upload limits and abuse controls (BLUEPRINT.md §14.2).
    case DOCUMENTS_MAX_PDF_BYTES = 'documents.max_pdf_bytes';
    case DOCUMENTS_MAX_IMAGE_BYTES = 'documents.max_image_bytes';
    case DOCUMENTS_MAX_IMAGE_PIXELS = 'documents.max_image_pixels';

    /** Pages, not bytes: length is what costs a provider its time. */
    case DOCUMENTS_MAX_PAGES = 'documents.max_pages';
    case DOCUMENTS_UPLOAD_MAX_ATTEMPTS = 'documents.upload_rate_limit_max_attempts';
    case DOCUMENTS_UPLOAD_DECAY_SECONDS = 'documents.upload_rate_limit_decay_seconds';
    case EXTRACTION_TIMEOUT_SECONDS = 'extraction.timeout_seconds';

    // Official source fetching (BLUEPRINT.md §7.1, §8.2). §7.1 requires the crawler to tolerate an
    // endpoint move without a code change — BNR relocated its feeds to curs.bnr.ro in 2026.
    case SOURCES_BNR_DAILY_URL = 'sources.bnr_daily_url';
    case SOURCES_BNR_YEARLY_URL_TEMPLATE = 'sources.bnr_yearly_url_template';
    case SOURCES_ECB_INSTITUTION_LIST_PAGE_URL = 'sources.ecb_institution_list_page_url';
    case SOURCES_GLEIF_BIC_LEI_PAGE_URL = 'sources.gleif_bic_lei_page_url';
    case SOURCES_GLEIF_LEGAL_FORMS_PAGE_URL = 'sources.gleif_legal_forms_page_url';
    case SOURCES_FETCH_TIMEOUT_SECONDS = 'sources.fetch_timeout_seconds';
    case SOURCES_FETCH_MAX_BYTES = 'sources.fetch_max_bytes';
    case SOURCES_FETCH_MAX_REDIRECTS = 'sources.fetch_max_redirects';

    /**
     * How the legislation portal is searched — its SOAP service, or its search page in the browser
     * worker — and how a law's pages are read: downloaded directly, or opened in the browser.
     *
     * Two settings rather than one because they fail apart: the portal's search index can be down while
     * its pages are served, and its pages can refuse a plain client while its search answers. Neither
     * falls back to the other — a failure says what failed.
     */
    case LEGISLATION_PORTAL_SEARCH = 'legislation.portal_search';
    case LEGISLATION_PORTAL_PAGES = 'legislation.portal_pages';

    /**
     * How long one request to the portal may take — its own, longer than the shared one: a
     * consolidation of the Codul fiscal is several megabytes, and the portal is slow to serve it.
     */
    case LEGISLATION_PORTAL_TIMEOUT_SECONDS = 'legislation.portal_timeout_seconds';

    /** The `User-Agent` of every source that goes out as a browser (ADR 0025 §3). */
    case SOURCES_BROWSER_USER_AGENT = 'sources.browser_user_agent';

    /** How the crawler names itself to a source that goes out as one, and who it may write to about it. */
    case SOURCES_CRAWLER_NAME = 'sources.crawler_name';
    case SOURCES_CRAWLER_CONTACT = 'sources.crawler_contact';

    // FX verification (BLUEPRINT.md §7.4).
    case FX_VERIFICATION_TOLERANCE = 'fx.verification_tolerance';

    /**
     * Which currencies this installation works in.
     *
     * The import is untouched by it — BNR publishes thirty-nine and all thirty-nine are stored,
     * because a rate nobody asked for still costs nothing and a missing year cannot be backfilled
     * later. This is the shorter list a person actually holds money in: what the account, transaction
     * and rental forms offer, and what the dashboard will chart.
     *
     * A setting rather than a constant in the frontend, which is where it used to be — two arrays in
     * two files that disagreed with each other and with the API, none of them editable without a
     * deploy.
     */
    case FX_CURRENCIES = 'fx.currencies';

    // SPV (BLUEPRINT.md §13).
    case SPV_MESSAGES_WINDOW_DAYS = 'spv.messages_window_days';
    case SPV_CHALLENGE_TTL_SECONDS = 'spv.challenge_ttl_seconds';
    case BROWSER_WORKER_TIMEOUT_SECONDS = 'browser_worker.timeout_seconds';

    // Authentication (BLUEPRINT.md §4.7).
    /**
     * Whether a stranger can create an account.
     *
     * A runtime setting rather than an env var because "open for sign-ups" is an operational policy
     * an owner changes — and changes back — not a property of the deployment. The switch is enforced
     * by `RegistrationController`, not merely hidden in the interface.
     */
    case IDENTITY_REGISTRATION_ENABLED = 'identity.registration_enabled';

    /**
     * Whether an account has to prove it can read its own address before it can use the application.
     *
     * Off unless somebody turns it on, and turning it on is not reversible in its effect: every
     * account that has not verified — which, the moment this is first enabled, is all of them — is
     * held at the verification screen until it does. There is deliberately no backfill; "already
     * registered" is not evidence that an address was ever readable by the person who typed it.
     *
     * Enforced by `Kern\Platform\Identity\Http\Middleware\RequireVerifiedEmail`, not merely
     * reflected in the interface, for the same reason the registration switch is enforced in its
     * controller. If mail is misconfigured while this is on, nobody can get in at all — which is what
     * `kern:identity:verify-email` exists for.
     */
    case IDENTITY_EMAIL_VERIFICATION_REQUIRED = 'identity.email_verification_required';

    case SECURITY_WEBAUTHN_CHALLENGE_TTL_SECONDS = 'security.webauthn_challenge_ttl_seconds';
    case SECURITY_WEBAUTHN_TIMEOUT_MS = 'security.webauthn_timeout_ms';
    case SECURITY_SESSION_LIFETIME_MINUTES = 'security.session_lifetime_minutes';
    case SECURITY_STEP_UP_WINDOW_SECONDS = 'security.step_up_window_seconds';

    // Display-only. See isReadOnly().
    case FX_DECIMAL_SCALE = 'fx.decimal_scale';
    case CRYPTO_KMS_DRIVER = 'crypto.kms_driver';
    case SECURITY_HASH_DRIVER = 'security.hash_driver';

    // Which countries' banks are kept from the ECB's list, which covers the whole EU.
    case BANKS_COUNTRIES = 'banks.countries';

    /**
     * `BLUEPRINT.md` §4.3/§4.10 — any API token/credential is encrypted at rest. Only the API key
     * carries secret material; the rest (which provider, which URL, whether it is on) are
     * operational configuration, not a credential, and are stored in plain text for a settings
     * screen to render directly without a decrypt round trip.
     */
    public function isSecret(): bool
    {
        return $this === self::AI_API_KEY;
    }

    /**
     * The provider's address and its key: written together or not at all, and only by
     * `ReplaceAiConnectionController`.
     *
     * A key is only ever sent to the address it was saved with. When the two could be changed apart,
     * a new address with the key left as it was sent the stored key wherever that address pointed —
     * so the general settings update refuses both, as it refuses a read-only key.
     */
    public function isSetOnlyWithItsPair(): bool
    {
        return in_array($this, [self::AI_BASE_URL, self::AI_API_KEY], true);
    }

    /**
     * Values that cannot be changed safely once data exists, and are therefore refused by the
     * **server**, not merely disabled in the UI. A disabled input is a hint; a rejected write is a
     * control.
     *
     * Each would silently corrupt existing data rather than fail loudly:
     *  - FX decimal scale: 17 763 rates are already stored at the current scale.
     *  - KMS driver / hash driver: existing ciphertexts and password hashes were produced by the
     *    current one and cannot be read or verified by another.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [
            self::FX_DECIMAL_SCALE,
            self::CRYPTO_KMS_DRIVER,
            self::SECURITY_HASH_DRIVER,
        ], true);
    }

    /** UI grouping. Keys in one group are saved together. */
    public function group(): SettingGroup
    {
        return match ($this) {
            self::AI_ENABLED, self::AI_PROVIDER, self::AI_BASE_URL, self::AI_MODEL,
            self::AI_API_KEY, self::AI_EXTRACTION_MAX_TOKENS,
            self::AI_EXTRACTION_TIMEOUT_SECONDS, self::AI_SUPPORTS_ENHANCEMENT,
            self::AI_SUPPORTS_CONVERSION => SettingGroup::AI,

            self::DOCUMENTS_MAX_PDF_BYTES, self::DOCUMENTS_MAX_IMAGE_BYTES,
            self::DOCUMENTS_MAX_IMAGE_PIXELS, self::DOCUMENTS_MAX_PAGES,
            self::DOCUMENTS_UPLOAD_MAX_ATTEMPTS,
            self::DOCUMENTS_UPLOAD_DECAY_SECONDS,
            self::EXTRACTION_TIMEOUT_SECONDS => SettingGroup::DOCUMENTS,

            self::SOURCES_BNR_DAILY_URL, self::SOURCES_BNR_YEARLY_URL_TEMPLATE,
            self::SOURCES_ECB_INSTITUTION_LIST_PAGE_URL, self::SOURCES_GLEIF_BIC_LEI_PAGE_URL,
            self::SOURCES_GLEIF_LEGAL_FORMS_PAGE_URL,
            self::SOURCES_FETCH_TIMEOUT_SECONDS, self::SOURCES_FETCH_MAX_BYTES,
            self::SOURCES_FETCH_MAX_REDIRECTS => SettingGroup::SOURCES,

            // What a request says about itself, in one place: the browser it goes out as, or the crawler.
            self::SOURCES_BROWSER_USER_AGENT, self::SOURCES_CRAWLER_NAME,
            self::SOURCES_CRAWLER_CONTACT => SettingGroup::BROWSER,

            self::LEGISLATION_PORTAL_SEARCH, self::LEGISLATION_PORTAL_PAGES,
            self::LEGISLATION_PORTAL_TIMEOUT_SECONDS => SettingGroup::LEGISLATION_PORTAL,

            self::FX_VERIFICATION_TOLERANCE, self::FX_DECIMAL_SCALE,
            self::FX_CURRENCIES => SettingGroup::FX,

            self::SPV_MESSAGES_WINDOW_DAYS, self::SPV_CHALLENGE_TTL_SECONDS => SettingGroup::SPV,
            // How long this installation waits on its browser worker. Not an SPV value: the worker
            // drives whatever a playbook describes, and SPV is one of those things.
            self::BROWSER_WORKER_TIMEOUT_SECONDS => SettingGroup::SPV,

            self::IDENTITY_REGISTRATION_ENABLED, self::IDENTITY_EMAIL_VERIFICATION_REQUIRED,
            self::SECURITY_WEBAUTHN_CHALLENGE_TTL_SECONDS, self::SECURITY_WEBAUTHN_TIMEOUT_MS,
            self::SECURITY_SESSION_LIFETIME_MINUTES, self::SECURITY_STEP_UP_WINDOW_SECONDS,
            self::CRYPTO_KMS_DRIVER, self::SECURITY_HASH_DRIVER => SettingGroup::SECURITY,

            self::BANKS_COUNTRIES => SettingGroup::BANKS,
        };
    }

    /** The `config()` path this key overrides — and the source of its fallback value. */
    public function configPath(): string
    {
        return match ($this) {
            self::AI_ENABLED => 'ai.providers.defaults.enabled',
            self::AI_PROVIDER => 'ai.providers.defaults.provider',
            self::AI_BASE_URL => 'ai.providers.defaults.base_url',
            self::AI_MODEL => 'ai.providers.defaults.model',
            self::AI_API_KEY => 'ai.providers.defaults.api_key',
            self::AI_EXTRACTION_MAX_TOKENS => 'ai.documents.max_tokens',
            self::AI_EXTRACTION_TIMEOUT_SECONDS => 'ai.documents.timeout_seconds',
            self::AI_SUPPORTS_ENHANCEMENT => 'ai.providers.supports_enhancement',
            self::AI_SUPPORTS_CONVERSION => 'ai.providers.supports_conversion',
            self::DOCUMENTS_MAX_PDF_BYTES => 'documents.max_pdf_bytes',
            self::DOCUMENTS_MAX_IMAGE_BYTES => 'documents.max_image_bytes',
            self::DOCUMENTS_MAX_IMAGE_PIXELS => 'documents.max_image_pixels',
            self::DOCUMENTS_MAX_PAGES => 'documents.max_pages',
            self::DOCUMENTS_UPLOAD_MAX_ATTEMPTS => 'documents.rate_limits.upload_per_subject.max_attempts',
            self::DOCUMENTS_UPLOAD_DECAY_SECONDS => 'documents.rate_limits.upload_per_subject.decay_seconds',
            self::EXTRACTION_TIMEOUT_SECONDS => 'documents.text_extraction.timeout_seconds',
            self::SOURCES_BNR_DAILY_URL => 'sources.entries.BNR.endpoints.daily_xml',
            self::SOURCES_BNR_YEARLY_URL_TEMPLATE => 'sources.entries.BNR.endpoints.yearly_xml_template',
            self::SOURCES_ECB_INSTITUTION_LIST_PAGE_URL => 'sources.entries.ECB.endpoints.institution_list_page',
            self::SOURCES_GLEIF_BIC_LEI_PAGE_URL => 'sources.entries.GLEIF.endpoints.bic_lei_page',
            self::SOURCES_GLEIF_LEGAL_FORMS_PAGE_URL => 'sources.entries.GLEIF.endpoints.legal_forms_page',
            self::SOURCES_FETCH_TIMEOUT_SECONDS => 'sources.fetch.timeout_seconds',
            self::SOURCES_FETCH_MAX_BYTES => 'sources.fetch.max_response_bytes',
            self::SOURCES_FETCH_MAX_REDIRECTS => 'sources.fetch.max_redirects',
            self::LEGISLATION_PORTAL_SEARCH => 'sources.entries.LEGISLATIE.search',
            self::LEGISLATION_PORTAL_PAGES => 'sources.entries.LEGISLATIE.pages',
            self::LEGISLATION_PORTAL_TIMEOUT_SECONDS => 'sources.entries.LEGISLATIE.timeout_seconds',
            self::SOURCES_BROWSER_USER_AGENT => 'sources.browser.user_agent',
            self::SOURCES_CRAWLER_NAME => 'sources.crawler.name',
            self::SOURCES_CRAWLER_CONTACT => 'sources.crawler.contact',
            self::FX_VERIFICATION_TOLERANCE => 'sources.fx.verification_tolerance',
            self::FX_DECIMAL_SCALE => 'sources.fx.decimal_scale',
            self::FX_CURRENCIES => 'sources.fx.currencies',
            self::SPV_MESSAGES_WINDOW_DAYS => 'spv.anaf_api.messages_window_days',
            self::SPV_CHALLENGE_TTL_SECONDS => 'spv.challenge_ttl_seconds',
            self::BROWSER_WORKER_TIMEOUT_SECONDS => 'browser_worker.timeout_seconds',
            self::IDENTITY_REGISTRATION_ENABLED => 'identity.registration.enabled',
            self::IDENTITY_EMAIL_VERIFICATION_REQUIRED => 'identity.verification.required',
            self::SECURITY_WEBAUTHN_CHALLENGE_TTL_SECONDS => 'webauthn.challenge_ttl_seconds',
            self::SECURITY_WEBAUTHN_TIMEOUT_MS => 'webauthn.timeout_ms',
            self::SECURITY_SESSION_LIFETIME_MINUTES => 'session.lifetime',
            self::SECURITY_STEP_UP_WINDOW_SECONDS => 'identity.step_up.window_seconds',
            self::CRYPTO_KMS_DRIVER => 'crypto.kms.driver',
            self::SECURITY_HASH_DRIVER => 'hashing.driver',
            self::BANKS_COUNTRIES => 'banks.countries',
        };
    }

    /** How a settings screen should render and validate this value. */
    public function type(): SettingType
    {
        return match ($this) {
            self::AI_ENABLED, self::AI_SUPPORTS_ENHANCEMENT, self::AI_SUPPORTS_CONVERSION,
            self::IDENTITY_REGISTRATION_ENABLED,
            self::IDENTITY_EMAIL_VERIFICATION_REQUIRED => SettingType::BOOLEAN,
            self::AI_API_KEY => SettingType::SECRET,
            self::AI_BASE_URL, self::SOURCES_BNR_DAILY_URL,
            self::SOURCES_BNR_YEARLY_URL_TEMPLATE, self::SOURCES_ECB_INSTITUTION_LIST_PAGE_URL,
            self::SOURCES_GLEIF_BIC_LEI_PAGE_URL, self::SOURCES_GLEIF_LEGAL_FORMS_PAGE_URL => SettingType::URL,
            self::FX_VERIFICATION_TOLERANCE => SettingType::DECIMAL,
            self::FX_CURRENCIES => SettingType::CURRENCY_LIST,
            self::BANKS_COUNTRIES => SettingType::COUNTRY_LIST,
            self::LEGISLATION_PORTAL_SEARCH, self::LEGISLATION_PORTAL_PAGES => SettingType::CHOICE,
            self::SOURCES_BROWSER_USER_AGENT, self::SOURCES_CRAWLER_NAME => SettingType::HEADER_VALUE,
            self::SOURCES_CRAWLER_CONTACT => SettingType::CONTACT,
            self::AI_PROVIDER, self::AI_MODEL, self::CRYPTO_KMS_DRIVER,
            self::SECURITY_HASH_DRIVER => SettingType::STRING,
            default => SettingType::INTEGER,
        };
    }

    /**
     * The values a `CHOICE` key may hold, as stored — empty for every other key.
     *
     * Kept beside the key rather than read from the code that acts on it, so the screen and the server
     * accept the same list and a value nothing understands cannot be saved.
     *
     * @return list<string>
     */
    public function choices(): array
    {
        return match ($this) {
            self::LEGISLATION_PORTAL_SEARCH => ['soap', 'browser'],
            self::LEGISLATION_PORTAL_PAGES => ['direct', 'browser'],
            default => [],
        };
    }
}
