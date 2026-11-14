<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use RuntimeException;

/**
 * The browser proxy as it stands: the Browser card's, over the environment's.
 *
 * Each value is the card's stored row where there is one, else the environment's — the password
 * included, which the overlay never carries, being a secret. The card writes all five together, so a
 * stored address always has its stored password beside it.
 */
final readonly class ResolveBrowserProxy
{
    public function __construct(
        private ConfigRepository $config,
        private SettingsRepository $settings,
    ) {
    }

    public function __invoke(): BrowserProxy
    {
        $stored = $this->settings->stored([
            SettingKey::BROWSER_PROXY_ENABLED, SettingKey::BROWSER_PROXY_SERVER, SettingKey::BROWSER_PROXY_USERNAME,
            SettingKey::BROWSER_PROXY_PASSWORD, SettingKey::BROWSER_PROXY_PLAYBOOKS,
        ]);
        // The card's rows first: within the request that saved them, `config()` still holds what it booted with.
        $value = function (SettingKey $key) use ($stored): mixed {
            return array_key_exists($key->value, $stored) ? $stored[$key->value]['value'] : $this->config->get($key->configPath());
        };
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $enabled = $value(SettingKey::BROWSER_PROXY_ENABLED);
        $enabled = is_bool($enabled) ? $enabled : SettingType::decodeBoolean(is_string($enabled) ? $enabled : null);

        if ($enabled === null) {
            throw new RuntimeException('config/browser_worker.php must say whether the proxy is on (BROWSER_WORKER_PROXY_ENABLED).');
        }

        $proxy = new BrowserProxy(
            enabled: $enabled,
            server: $text($value(SettingKey::BROWSER_PROXY_SERVER)),
            username: $text($value(SettingKey::BROWSER_PROXY_USERNAME)),
            password: $text($value(SettingKey::BROWSER_PROXY_PASSWORD)),
            playbooks: array_values(array_filter(array_map('trim', explode(',', $text($value(SettingKey::BROWSER_PROXY_PLAYBOOKS)) ?? '')))),
        );

        if ($enabled && ($proxy->server === null || $proxy->username === null || $proxy->password === null)) {
            throw new RuntimeException('The browser proxy is on without its address, user name and password (BROWSER_WORKER_PROXY_*).');
        }

        return $proxy;
    }
}
