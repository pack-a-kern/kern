<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Kern\Platform\Settings\Enums\SettingGroup;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingSource;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\ResolveSettings;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('reports a value as inherited when nothing overrides it', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);

    $resolved = app(ResolveSettings::class)->one(SettingKey::SPV_MESSAGES_WINDOW_DAYS);

    expect($resolved->source)->toBe(SettingSource::INHERITED)
        ->and($resolved->value)->toBe('50')
        ->and($resolved->readOnly)->toBeFalse();
});

it('reports a value as a database override once one is stored, and that override wins', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);
    app(SettingsRepository::class)->set(SettingKey::SPV_MESSAGES_WINDOW_DAYS, '90', null);

    $resolved = app(ResolveSettings::class)->one(SettingKey::SPV_MESSAGES_WINDOW_DAYS);

    expect($resolved->source)->toBe(SettingSource::DATABASE)
        ->and($resolved->value)->toBe('90');
});

it('falls back to the inherited value again once the override is cleared', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);
    $repo = app(SettingsRepository::class);

    $repo->set(SettingKey::SPV_MESSAGES_WINDOW_DAYS, '90', null);
    $repo->clear(SettingKey::SPV_MESSAGES_WINDOW_DAYS, null);

    $resolved = app(ResolveSettings::class)->one(SettingKey::SPV_MESSAGES_WINDOW_DAYS);

    expect($resolved->source)->toBe(SettingSource::INHERITED)
        ->and($resolved->value)->toBe('50');
});

it('never returns a secret value, only whether one is set and a last-four hint', function (): void {
    app(SettingsRepository::class)->set(SettingKey::AI_API_KEY, 'sk-live-secret-ABCD', null);

    $resolved = app(ResolveSettings::class)->one(SettingKey::AI_API_KEY);

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

    expect(app(ResolveSettings::class)->one(SettingKey::AI_API_KEY)->hint)->toBeNull();
});

it('marks the four unsafe-to-change settings read-only', function (): void {
    $readOnly = array_values(array_filter(
        SettingKey::cases(),
        fn (SettingKey $k): bool => app(ResolveSettings::class)->one($k)->readOnly,
    ));

    expect(array_map(fn (SettingKey $k): string => $k->value, $readOnly))
        ->toEqualCanonicalizing([
            'fx.decimal_scale',
            'crypto.blind_index_current_version',
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
