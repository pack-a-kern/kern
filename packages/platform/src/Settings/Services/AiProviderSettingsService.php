<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Settings\DataTransferObjects\AiProviderPublicView;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * The application service behind `PUT /api/v1/settings/ai/connection`: replaces the provider's
 * address and key together — each write recording exactly one tamper-evident audit event (`BLUEPRINT.md` §4.8) naming *who* changed
 * *what*, **never the value of the secret itself** (`BLUEPRINT.md` §4.10 — only whether the key
 * changed, never its bytes, not even hashed into the audit payload).
 *
 * Audit events carry `subjectId: null`, which `AuditChainService` routes to its dedicated
 * `SYSTEM_CHAIN_KEY` chain — the correct chain for an event with no `TaxSubject`, exactly as
 * intended for a system-wide setting.
 */
final readonly class AiProviderSettingsService
{
    private const array KEYS = [
        SettingKey::AI_ENABLED,
        SettingKey::AI_PROVIDER,
        SettingKey::AI_BASE_URL,
        SettingKey::AI_MODEL,
        SettingKey::AI_API_KEY,
    ];

    public function __construct(
        private SettingsRepository $settings,
        private ResolveAiProviderCredentials $resolveCredentials,
        private AuditChainService $audit,
    ) {
    }

    public function currentView(): AiProviderPublicView
    {
        $credentials = ($this->resolveCredentials)();
        $meta = $this->settings->latestUpdateMeta(self::KEYS);

        return new AiProviderPublicView(
            enabled: $credentials->enabled,
            provider: $credentials->provider,
            baseUrl: $credentials->baseUrl,
            model: $credentials->model,
            apiKeySet: $credentials->apiKey !== null,
            apiKeyLastFour: $credentials->apiKey !== null ? substr($credentials->apiKey, -4) : null,
            updatedAt: $meta['updatedAt'],
            updatedBy: $meta['updatedBy'],
        );
    }

    /**
     * The provider's address and its key, replaced together — the only way either changes.
     *
     * A null key is "this provider takes none", stored as no key: there is no other source it could
     * fall back to any more (`config/ai/providers.php` `defaults`). Never the old key: an address the
     * key was not saved with is exactly where it must not go.
     */
    public function replaceConnection(string $baseUrl, ?string $apiKey, string $actorUserId): AiProviderPublicView
    {
        DB::transaction(function () use ($baseUrl, $apiKey, $actorUserId): void {
            $this->settings->set(SettingKey::AI_BASE_URL, $baseUrl, $actorUserId);

            if ($apiKey === null) {
                $this->settings->clear(SettingKey::AI_API_KEY, $actorUserId);
            } else {
                $this->settings->set(SettingKey::AI_API_KEY, $apiKey, $actorUserId);
            }

            // Whether a key was set, never the key — not even hashed (BLUEPRINT.md §4.10).
            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: $actorUserId,
                eventType: 'AiProviderConnectionReplaced',
                resourceType: 'Setting',
                resourceId: 'ai',
                payloadHash: $this->audit->hashPayload([
                    'base_url' => $baseUrl,
                    'api_key_set' => $apiKey !== null,
                ]),
            ));
        });

        return $this->currentView();
    }
}
