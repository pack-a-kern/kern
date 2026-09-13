<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

/**
 * The closed set of system-wide settings this module manages. Deliberately **not** an open,
 * arbitrary-string key/value store (`docs/CODE-QUALITY.md` §2 — no speculative generality): every
 * valid key is named here, so a caller can never write to a key nothing reads, and
 * `App\Domain\Settings\Repositories\SettingsRepository` can decide, per key, whether the value is
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

    // Upload limits and abuse controls (BLUEPRINT.md §14.2).
    case DOCUMENTS_MAX_PDF_BYTES = 'documents.max_pdf_bytes';
    case DOCUMENTS_MAX_IMAGE_BYTES = 'documents.max_image_bytes';
    case DOCUMENTS_MAX_IMAGE_PIXELS = 'documents.max_image_pixels';
    case DOCUMENTS_UPLOAD_MAX_ATTEMPTS = 'documents.upload_rate_limit_max_attempts';
    case DOCUMENTS_UPLOAD_DECAY_SECONDS = 'documents.upload_rate_limit_decay_seconds';
    case EXTRACTION_TIMEOUT_SECONDS = 'extraction.timeout_seconds';

    // Official source fetching (BLUEPRINT.md §7.1, §8.2). §7.1 requires the crawler to tolerate an
    // endpoint move without a code change — BNR relocated its feeds to curs.bnr.ro in 2026.
    case SOURCES_BNR_DAILY_URL = 'sources.bnr_daily_url';
    case SOURCES_BNR_YEARLY_URL_TEMPLATE = 'sources.bnr_yearly_url_template';
    case SOURCES_FETCH_TIMEOUT_SECONDS = 'sources.fetch_timeout_seconds';
    case SOURCES_FETCH_MAX_BYTES = 'sources.fetch_max_bytes';
    case SOURCES_FETCH_MAX_REDIRECTS = 'sources.fetch_max_redirects';

    // FX verification (BLUEPRINT.md §7.4).
    case FX_VERIFICATION_TOLERANCE = 'fx.verification_tolerance';

    // SPV (BLUEPRINT.md §13).
    case SPV_MESSAGES_WINDOW_DAYS = 'spv.messages_window_days';
    case SPV_CHALLENGE_TTL_SECONDS = 'spv.challenge_ttl_seconds';
    case SPV_REQUEST_TIMEOUT_SECONDS = 'spv.request_timeout_seconds';

    // Authentication (BLUEPRINT.md §4.7).
    /**
     * Whether a stranger can create an account.
     *
     * A runtime setting rather than an env var because "open for sign-ups" is an operational policy
     * an owner changes — and changes back — not a property of the deployment. The switch is enforced
     * by `RegistrationController`, not merely hidden in the interface.
     */
    case IDENTITY_REGISTRATION_ENABLED = 'identity.registration_enabled';

    case SECURITY_WEBAUTHN_CHALLENGE_TTL_SECONDS = 'security.webauthn_challenge_ttl_seconds';
    case SECURITY_WEBAUTHN_TIMEOUT_MS = 'security.webauthn_timeout_ms';
    case SECURITY_SESSION_LIFETIME_MINUTES = 'security.session_lifetime_minutes';

    // Display-only. See isReadOnly().
    case FX_DECIMAL_SCALE = 'fx.decimal_scale';
    case CRYPTO_BLIND_INDEX_VERSION = 'crypto.blind_index_current_version';
    case CRYPTO_KMS_DRIVER = 'crypto.kms_driver';
    case SECURITY_HASH_DRIVER = 'security.hash_driver';

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
     * Values that cannot be changed safely once data exists, and are therefore refused by the
     * **server**, not merely disabled in the UI. A disabled input is a hint; a rejected write is a
     * control.
     *
     * Each would silently corrupt existing data rather than fail loudly:
     *  - blind-index version: every stored index was computed with the current key, so changing it
     *    breaks login-by-email and duplicate-CNP detection — as "no such record", not an error.
     *  - FX decimal scale: 17 763 rates are already stored at the current scale.
     *  - KMS driver / hash driver: existing ciphertexts and password hashes were produced by the
     *    current one and cannot be read or verified by another.
     */
    public function isReadOnly(): bool
    {
        return in_array($this, [
            self::FX_DECIMAL_SCALE,
            self::CRYPTO_BLIND_INDEX_VERSION,
            self::CRYPTO_KMS_DRIVER,
            self::SECURITY_HASH_DRIVER,
        ], true);
    }

    /** UI grouping. Keys in one group are saved together. */
    public function group(): SettingGroup
    {
        return match ($this) {
            self::AI_ENABLED, self::AI_PROVIDER, self::AI_BASE_URL, self::AI_MODEL,
            self::AI_API_KEY => SettingGroup::AI,

            self::DOCUMENTS_MAX_PDF_BYTES, self::DOCUMENTS_MAX_IMAGE_BYTES,
            self::DOCUMENTS_MAX_IMAGE_PIXELS, self::DOCUMENTS_UPLOAD_MAX_ATTEMPTS,
            self::DOCUMENTS_UPLOAD_DECAY_SECONDS,
            self::EXTRACTION_TIMEOUT_SECONDS => SettingGroup::DOCUMENTS,

            self::SOURCES_BNR_DAILY_URL, self::SOURCES_BNR_YEARLY_URL_TEMPLATE,
            self::SOURCES_FETCH_TIMEOUT_SECONDS, self::SOURCES_FETCH_MAX_BYTES,
            self::SOURCES_FETCH_MAX_REDIRECTS => SettingGroup::SOURCES,

            self::FX_VERIFICATION_TOLERANCE, self::FX_DECIMAL_SCALE => SettingGroup::FX,

            self::SPV_MESSAGES_WINDOW_DAYS, self::SPV_CHALLENGE_TTL_SECONDS,
            self::SPV_REQUEST_TIMEOUT_SECONDS => SettingGroup::SPV,

            self::IDENTITY_REGISTRATION_ENABLED,
            self::SECURITY_WEBAUTHN_CHALLENGE_TTL_SECONDS, self::SECURITY_WEBAUTHN_TIMEOUT_MS,
            self::SECURITY_SESSION_LIFETIME_MINUTES, self::CRYPTO_BLIND_INDEX_VERSION,
            self::CRYPTO_KMS_DRIVER, self::SECURITY_HASH_DRIVER => SettingGroup::SECURITY,
        };
    }

    /** The `config()` path this key overrides — and the source of its fallback value. */
    public function configPath(): string
    {
        return match ($this) {
            self::AI_ENABLED => 'settings.ai.env_fallback.enabled',
            self::AI_PROVIDER => 'settings.ai.env_fallback.provider',
            self::AI_BASE_URL => 'settings.ai.env_fallback.base_url',
            self::AI_MODEL => 'settings.ai.env_fallback.model',
            self::AI_API_KEY => 'settings.ai.env_fallback.api_key',
            self::DOCUMENTS_MAX_PDF_BYTES => 'documents.max_pdf_bytes',
            self::DOCUMENTS_MAX_IMAGE_BYTES => 'documents.max_image_bytes',
            self::DOCUMENTS_MAX_IMAGE_PIXELS => 'documents.max_image_pixels',
            self::DOCUMENTS_UPLOAD_MAX_ATTEMPTS => 'documents.rate_limits.upload_per_subject.max_attempts',
            self::DOCUMENTS_UPLOAD_DECAY_SECONDS => 'documents.rate_limits.upload_per_subject.decay_seconds',
            self::EXTRACTION_TIMEOUT_SECONDS => 'documents.text_extraction.timeout_seconds',
            self::SOURCES_BNR_DAILY_URL => 'sources.entries.BNR.endpoints.daily_xml',
            self::SOURCES_BNR_YEARLY_URL_TEMPLATE => 'sources.entries.BNR.endpoints.yearly_xml_template',
            self::SOURCES_FETCH_TIMEOUT_SECONDS => 'sources.fetch.timeout_seconds',
            self::SOURCES_FETCH_MAX_BYTES => 'sources.fetch.max_response_bytes',
            self::SOURCES_FETCH_MAX_REDIRECTS => 'sources.fetch.max_redirects',
            self::FX_VERIFICATION_TOLERANCE => 'sources.fx.verification_tolerance',
            self::FX_DECIMAL_SCALE => 'sources.fx.decimal_scale',
            self::SPV_MESSAGES_WINDOW_DAYS => 'spv.anaf_api.messages_window_days',
            self::SPV_CHALLENGE_TTL_SECONDS => 'spv.challenge_ttl_seconds',
            self::SPV_REQUEST_TIMEOUT_SECONDS => 'spv.browser_worker.timeout_seconds',
            self::IDENTITY_REGISTRATION_ENABLED => 'identity.registration.enabled',
            self::SECURITY_WEBAUTHN_CHALLENGE_TTL_SECONDS => 'webauthn.challenge_ttl_seconds',
            self::SECURITY_WEBAUTHN_TIMEOUT_MS => 'webauthn.timeout_ms',
            self::SECURITY_SESSION_LIFETIME_MINUTES => 'session.lifetime',
            self::CRYPTO_BLIND_INDEX_VERSION => 'crypto.blind_index.current_version',
            self::CRYPTO_KMS_DRIVER => 'crypto.kms.driver',
            self::SECURITY_HASH_DRIVER => 'hashing.driver',
        };
    }

    /** How a settings screen should render and validate this value. */
    public function type(): SettingType
    {
        return match ($this) {
            self::AI_ENABLED, self::IDENTITY_REGISTRATION_ENABLED => SettingType::BOOLEAN,
            self::AI_API_KEY => SettingType::SECRET,
            self::AI_BASE_URL, self::SOURCES_BNR_DAILY_URL,
            self::SOURCES_BNR_YEARLY_URL_TEMPLATE => SettingType::URL,
            self::FX_VERIFICATION_TOLERANCE => SettingType::DECIMAL,
            self::AI_PROVIDER, self::AI_MODEL, self::CRYPTO_KMS_DRIVER,
            self::SECURITY_HASH_DRIVER => SettingType::STRING,
            default => SettingType::INTEGER,
        };
    }
}
