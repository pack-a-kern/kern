<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Settings\DataTransferObjects\ResolvedSetting;
use Kern\Platform\Settings\Enums\SettingGroup;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingSource;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\ResolveSettings;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/** One key, as the settings screen shows it: read through its group, the way the screen reads it. */
function resolvedSetting(SettingKey $key): ResolvedSetting
{
    foreach (app(ResolveSettings::class)->forGroup($key->group()) as $setting) {
        if ($setting->key === $key) {
            return $setting;
        }
    }

    throw new LogicException("{$key->value} is not in its own group.");
}

it('reports a value as inherited when nothing overrides it', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);

    $resolved = resolvedSetting(SettingKey::SPV_MESSAGES_WINDOW_DAYS);

    expect($resolved->source)->toBe(SettingSource::INHERITED)
        ->and($resolved->value)->toBe('50')
        ->and($resolved->readOnly)->toBeFalse();
});

it('reports a value as a database override once one is stored, and that override wins', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);
    app(SettingsRepository::class)->set(SettingKey::SPV_MESSAGES_WINDOW_DAYS, '90', null);

    $resolved = resolvedSetting(SettingKey::SPV_MESSAGES_WINDOW_DAYS);

    expect($resolved->source)->toBe(SettingSource::DATABASE)
        ->and($resolved->value)->toBe('90');
});

it('falls back to the inherited value again once the override is cleared', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);
    $repo = app(SettingsRepository::class);

    $repo->set(SettingKey::SPV_MESSAGES_WINDOW_DAYS, '90', null);
    $repo->clear(SettingKey::SPV_MESSAGES_WINDOW_DAYS, null);

    $resolved = resolvedSetting(SettingKey::SPV_MESSAGES_WINDOW_DAYS);

    expect($resolved->source)->toBe(SettingSource::INHERITED)
        ->and($resolved->value)->toBe('50');
});

it('never returns a secret value, only whether one is set and a last-four hint', function (): void {
    app(SettingsRepository::class)->set(SettingKey::AI_API_KEY, 'sk-live-secret-ABCD', null);

    $resolved = resolvedSetting(SettingKey::AI_API_KEY);

    expect($resolved->value)->toBeNull()
        ->and($resolved->hasValue)->toBeTrue()
        ->and($resolved->hint)->toBe('ABCD')
        ->and($resolved->toArray())->not->toHaveKey('secret');

    // The full secret must appear nowhere in the serialized payload.
    expect(json_encode($resolved->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('sk-live-secret-ABCD');
});

it('gives no hint at all for a short secret, rather than most of it', function (): void {
    app(SettingsRepository::class)->set(SettingKey::AI_API_KEY, 'sk-123', null);

    expect(resolvedSetting(SettingKey::AI_API_KEY)->hint)->toBeNull();
});

it('marks the three unsafe-to-change settings read-only', function (): void {
    $readOnly = array_values(array_filter(
        SettingKey::cases(),
        fn (SettingKey $k): bool => resolvedSetting($k)->readOnly,
    ));

    expect(array_map(fn (SettingKey $k): string => $k->value, $readOnly))
        ->toEqualCanonicalizing([
            'fx.decimal_scale',
            'crypto.kms_driver',
            'security.hash_driver',
        ]);
});

it('resolves every group without error and covers every key exactly once', function (): void {
    $all = app(ResolveSettings::class)->all();

    $keys = [];
    foreach ($all as $group => $settings) {
        expect(SettingGroup::tryFrom($group))->not->toBeNull();
        foreach ($settings as $setting) {
            $keys[] = $setting->key->value;
        }
    }

    expect($keys)->toHaveCount(count(SettingKey::cases()))
        ->and(array_unique($keys))->toHaveCount(count(SettingKey::cases()));
});

it('reads the whole settings screen in one query, each key with its own last change', function (): void {
    $repo = app(SettingsRepository::class);
    $repo->set(SettingKey::SPV_MESSAGES_WINDOW_DAYS, '90', null);
    $repo->set(SettingKey::AI_API_KEY, 'sk-live-secret-ABCD', null);

    DB::enableQueryLog();
    $all = app(ResolveSettings::class)->all();
    // The table's reads only: opening the secret also loads the key that decrypts it, as it did before.
    $queries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "settings"'));

    $settings = array_merge(...array_values($all));
    $stored = array_values(array_filter($settings, static fn ($setting): bool => $setting->source === SettingSource::DATABASE));

    // Two per key, before: seventy-eight for this screen.
    expect($queries)->toHaveCount(1)
        ->and(array_map(static fn ($setting): string => $setting->key->value, $stored))
        ->toEqualCanonicalizing([SettingKey::SPV_MESSAGES_WINDOW_DAYS->value, SettingKey::AI_API_KEY->value])
        ->and(array_filter($stored, static fn ($setting): bool => $setting->updatedAt === null))->toBe([])
        ->and(array_filter($settings, static fn ($setting): bool => $setting->source === SettingSource::INHERITED && $setting->updatedAt !== null))->toBe([]);
});
