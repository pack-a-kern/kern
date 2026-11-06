<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Kern\Platform\Settings\DataTransferObjects\ResolvedSetting;
use Kern\Platform\Settings\Enums\SettingGroup;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingSource;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;

/**
 * Reads settings for display: the effective value, where it came from, and whether it is editable.
 *
 * A database override always wins; otherwise the value falls back to the key's declared config path
 * (verified to exist by `ConfigPathResolutionTest`), which is itself an env var or a code default.
 *
 * Secrets never leave this class as values — `ResolvedSetting::$value` stays null for them, and the
 * screen gets `hasValue` plus a last-four `hint` instead.
 */
final readonly class ResolveSettings
{
    public function __construct(private SettingsRepository $settings)
    {
    }

    /** @return list<ResolvedSetting> */
    public function forGroup(SettingGroup $group): array
    {
        return $this->resolve($group->keys());
    }

    /**
     * Every group, from one read of the table.
     *
     * @return array<string, list<ResolvedSetting>> keyed by group
     */
    public function all(): array
    {
        $out = [];

        foreach (SettingGroup::cases() as $group) {
            $out[$group->value] = [];
        }

        foreach ($this->resolve(SettingKey::cases()) as $setting) {
            $out[$setting->key->group()->value][] = $setting;
        }

        return $out;
    }

    /**
     * @param  list<SettingKey>  $keys
     * @return list<ResolvedSetting> in the order given
     */
    private function resolve(array $keys): array
    {
        $stored = $this->settings->stored($keys);

        return array_map(fn (SettingKey $key): ResolvedSetting => $this->one($key, $stored[$key->value] ?? null), $keys);
    }

    /** @param  array{value: ?string, updatedAt: ?CarbonImmutable, updatedBy: ?string}|null  $stored */
    private function one(SettingKey $key, ?array $stored): ResolvedSetting
    {
        $override = $stored['value'] ?? null;
        $source = $override !== null ? SettingSource::DATABASE : SettingSource::INHERITED;

        $effective = $override ?? $this->configValue($key);
        $isSecret = $key->type() === SettingType::SECRET;
        $updatedAt = $stored['updatedAt'] ?? null;

        return new ResolvedSetting(
            key: $key,
            type: $key->type(),
            source: $source,
            // A secret is never disclosed, whatever its source.
            value: $isSecret ? null : $effective,
            hasValue: $effective !== null && $effective !== '',
            hint: $isSecret ? $this->lastFour($effective) : null,
            readOnly: $key->isReadOnly(),
            configPath: $key->configPath(),
            updatedAt: $updatedAt?->toIso8601String(),
            updatedBy: $stored['updatedBy'] ?? null,
        );
    }

    private function configValue(SettingKey $key): ?string
    {
        $value = Config::get($key->configPath());

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            // A list of codes is shown as it is typed and stored: comma-separated.
            is_array($value) && $key->type() === SettingType::COUNTRY_LIST => implode(',', array_filter($value, is_string(...))),
            // A non-scalar config value cannot be rendered in a text input; showing nothing is
            // honest, showing "Array" is not.
            default => null,
        };
    }

    /**
     * Enough to recognise a key you set, not enough to reconstruct it. Short values yield no hint
     * at all rather than most of the secret.
     */
    private function lastFour(?string $secret): ?string
    {
        if ($secret === null || mb_strlen($secret) < 8) {
            return null;
        }

        return mb_substr($secret, -4);
    }
}
