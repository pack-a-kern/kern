<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\BrowserWorker\RegisteredPlaybooks;
use Kern\Platform\BrowserWorker\ResolveBrowserProxy;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * `PUT /api/v1/settings/browser/proxy`: the proxy's switch, address, credentials and playbooks, written
 * together — the only way any of them changes — with one audit event naming who changed what, never the
 * password (`BLUEPRINT.md` §4.10), as the AI key is recorded.
 *
 * Off empties the address and the credentials rather than keeping them for later: a proxy that is off
 * holds nothing that could be sent anywhere.
 */
final readonly class BrowserProxySettingsService
{
    private const array KEYS = [
        SettingKey::BROWSER_PROXY_ENABLED,
        SettingKey::BROWSER_PROXY_SERVER,
        SettingKey::BROWSER_PROXY_USERNAME,
        SettingKey::BROWSER_PROXY_PASSWORD,
        SettingKey::BROWSER_PROXY_PLAYBOOKS,
    ];

    public function __construct(
        private SettingsRepository $settings,
        private ResolveBrowserProxy $resolve,
        private RegisteredPlaybooks $playbooks,
        private AuditChainService $audit,
    ) {
    }

    /** @return array<string, mixed> never the password, only whether one is set */
    public function currentView(): array
    {
        $proxy = ($this->resolve)();
        $meta = $this->settings->latestUpdateMeta(self::KEYS);

        return [
            'enabled' => $proxy->enabled,
            'server' => $proxy->server,
            'username' => $proxy->username,
            'password_set' => $proxy->password !== null,
            'playbooks' => $proxy->playbooks,
            'available_playbooks' => $this->playbooks->names(),
            'updated_at' => $meta['updatedAt']?->toIso8601String(),
            'updated_by' => $meta['updatedBy'],
        ];
    }

    /**
     * On with its address and credentials, or off with none. The caller has checked both shapes.
     *
     * @param  list<string>  $playbooks
     * @return array<string, mixed>
     */
    public function replace(bool $enabled, ?string $server, ?string $username, ?string $password, array $playbooks, string $actorUserId): array
    {
        DB::transaction(function () use ($enabled, $server, $username, $password, $playbooks, $actorUserId): void {
            $this->settings->set(SettingKey::BROWSER_PROXY_ENABLED, SettingType::encodeBoolean($enabled), $actorUserId);
            // Empty rather than cleared: a cleared key falls back to `.env`, and off means nothing at all.
            $this->settings->set(SettingKey::BROWSER_PROXY_SERVER, $enabled ? (string) $server : '', $actorUserId);
            $this->settings->set(SettingKey::BROWSER_PROXY_USERNAME, $enabled ? (string) $username : '', $actorUserId);
            $this->settings->set(SettingKey::BROWSER_PROXY_PASSWORD, $enabled ? (string) $password : '', $actorUserId);
            $this->settings->set(SettingKey::BROWSER_PROXY_PLAYBOOKS, implode(',', $playbooks), $actorUserId);

            // Whether a password was set, never the password — not even hashed (BLUEPRINT.md §4.10).
            $this->audit->record(AuditEventInput::occurringNow(
                subjectId: null,
                actorId: $actorUserId,
                eventType: 'BrowserProxyReplaced',
                resourceType: 'Setting',
                resourceId: 'browser_worker.proxy',
                payloadHash: $this->audit->hashPayload([
                    'enabled' => $enabled,
                    'server' => $enabled ? $server : null,
                    'username' => $enabled ? $username : null,
                    'password_set' => $enabled,
                    'playbooks' => $playbooks,
                ]),
            ));
        });

        return $this->currentView();
    }
}
