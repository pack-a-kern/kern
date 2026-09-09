<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Settings\DataTransferObjects\AiProviderPublicView;
use App\Domain\Settings\DataTransferObjects\UpdateAiProviderSettingsInput;
use App\Domain\Settings\Enums\SettingKey;
use App\Domain\Settings\Repositories\SettingsRepository;

/**
 * The application service behind the `/api/v1/settings/ai` HTTP surface: reads the current
 * (redacted) configuration, applies an update, and clears the stored API key — each write
 * recording exactly one tamper-evident audit event (`BLUEPRINT.md` §4.8) naming *who* changed
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

    public function update(UpdateAiProviderSettingsInput $input, string $actorUserId): AiProviderPublicView
    {
        $this->settings->set(SettingKey::AI_ENABLED, $input->enabled ? 'true' : 'false', $actorUserId);
        $this->settings->set(SettingKey::AI_PROVIDER, $input->provider, $actorUserId);
        $this->settings->set(SettingKey::AI_BASE_URL, $input->baseUrl, $actorUserId);
        $this->settings->set(SettingKey::AI_MODEL, $input->model, $actorUserId);

        $apiKeyChanged = $input->apiKey !== null;

        if ($apiKeyChanged) {
            $this->settings->set(SettingKey::AI_API_KEY, $input->apiKey, $actorUserId);
        }

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorUserId,
            eventType: 'AiProviderSettingsUpdated',
            resourceType: 'Setting',
            resourceId: 'ai',
            payloadHash: $this->audit->hashPayload([
                'enabled' => $input->enabled,
                'provider' => $input->provider,
                'base_url' => $input->baseUrl,
                'model' => $input->model,
                'api_key_changed' => $apiKeyChanged,
            ]),
        ));

        return $this->currentView();
    }

    public function clearApiKey(string $actorUserId): AiProviderPublicView
    {
        $this->settings->clear(SettingKey::AI_API_KEY, $actorUserId);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorUserId,
            eventType: 'AiApiKeySettingCleared',
            resourceType: 'Setting',
            resourceId: 'ai',
            payloadHash: $this->audit->hashPayload(['cleared' => true]),
        ));

        return $this->currentView();
    }
}
