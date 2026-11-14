<?php

declare(strict_types=1);

namespace Kern\Legislation\Services;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\BrowserWorker\BrowserEngine;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * `PUT /api/v1/settings/browser/identity`: the browser engine and the `User-Agent` every request and every
 * browser goes out as, written together — the only way either changes — with one audit event naming who
 * chose which.
 */
final readonly class BrowserIdentitySettings
{
    public function __construct(
        private SettingsRepository $settings,
        private AuditChainService $audit,
    ) {
    }

    public function replace(BrowserEngine $engine, string $userAgent, string $actorUserId): void
    {
        DB::transaction(function () use ($engine, $userAgent, $actorUserId): void {
            $this->settings->set(SettingKey::SOURCES_BROWSER_ENGINE, $engine->value, $actorUserId);
            $this->settings->set(SettingKey::SOURCES_BROWSER_USER_AGENT, $userAgent, $actorUserId);

            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: $actorUserId,
                eventType: 'BrowserIdentityReplaced',
                resourceType: 'Setting',
                resourceId: 'sources.browser',
                payloadHash: $this->audit->hashPayload(['engine' => $engine->value, 'user_agent' => $userAgent]),
            ));
        });
    }
}
