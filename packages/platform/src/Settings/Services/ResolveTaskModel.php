<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * The effective model configured for one AI task: the admin's saved override when there is one,
 * otherwise the module's own `config()` default (an env var — {@see SettingKey::configPath()}).
 *
 * The single place that applies that precedence, so the settings screen (through
 * {@see AiTaskModels}) and every service that runs the task — the statement reader, the counterparty
 * classifier, the identity-card reader, the law reader — all agree on which model is in effect. Were
 * a consumer to read `config()` directly instead, an admin's override would show on the screen but
 * never reach the work.
 *
 * Returns null when neither the override nor the default names a model; an empty string, saved or
 * configured, counts as "none". The base-model fallback a task uses when it has none of its own is
 * the caller's to apply, because it differs per task (an optional second reader has no fallback).
 * Resolved fresh on every call, like every other settings read, so a saved model takes effect at
 * once.
 */
final readonly class ResolveTaskModel
{
    public function __construct(
        private SettingsRepository $settings,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(SettingKey $key): ?string
    {
        return $this->many([$key])[$key->value];
    }

    /**
     * The effective model for several task keys from one read of the settings table — the override
     * when there is one, else each key's config default. For the settings screen, which resolves a
     * handful of task models at once and would otherwise query the table once per key.
     *
     * @param  list<SettingKey>  $keys
     * @return array<string, ?string> keyed by {@see SettingKey::value}, every key present
     */
    public function many(array $keys): array
    {
        $stored = $this->settings->stored($keys);
        $resolved = [];

        foreach ($keys as $key) {
            $override = $stored[$key->value]['value'] ?? null;

            $resolved[$key->value] = is_string($override) && trim($override) !== ''
                ? trim($override)
                : $this->configuredModel($key);
        }

        return $resolved;
    }

    private function configuredModel(SettingKey $key): ?string
    {
        $configured = $this->config->get($key->configPath());

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
