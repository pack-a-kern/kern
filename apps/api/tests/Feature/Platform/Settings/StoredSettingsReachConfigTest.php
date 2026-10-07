<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Settings\StoredSettingsReachConfigTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Enums\SettingType;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\StoredSettingsOverlay;

/**
 * Every setting the screen can write must reach the configuration the application reads.
 *
 * This exists because thirty-one of thirty-eight did not. They wrote a row, the app went on reading
 * `config()` from files and environment, and the screen read the stored value back to draw itself — so it
 * showed what you had typed while the behaviour it named was unchanged. Seven worked, because three
 * services asked the repository directly, and nothing distinguished the two groups by looking.
 *
 * So the test walks the enum rather than naming settings. A key added without being wired fails here,
 * which is the only way this stays fixed: the per-setting version of this test would have been written for
 * the seven and never for the thirty-one.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

it('points every setting at a configuration key some config file actually declares', function (): void {
    /*
     * The half of this that is not circular.
     *
     * Asserting that the overlay sets the path a key names and then reading that same path back proves
     * only that `config()->set()` works. What matters is whether the path is one the application reads —
     * and the closest honest proxy is whether any config file declares it at all. A setting pointing at
     * `documents.upload_limit` when the file says `documents.rate_limits.upload_per_subject.max_attempts`
     * would store, display, overlay and do nothing, which is exactly the failure this file exists for.
     */
    $undeclared = [];

    foreach (SettingKey::cases() as $key) {
        if (! config()->has($key->configPath())) {
            $undeclared[] = $key->value . ' -> ' . $key->configPath();
        }
    }

    expect($undeclared)->toBe([]);
});

it('puts every stored setting in front of the configured default', function (): void {
    $repository = app(SettingsRepository::class);
    $ignored = [];

    foreach (SettingKey::cases() as $key) {
        if ($key->type() === SettingType::SECRET) {
            // Enciphered under a KMS key, so unwrapping one on every request's boot is a round trip this
            // overlay will not make. A secret stays the business of whoever wants it, through the
            // repository — `AiProviderSettingsService` is the example.
            continue;
        }

        $stored = storableValueFor($key);
        $repository->set($key, $stored, null);
        StoredSettingsOverlay::forget();

        app(StoredSettingsOverlay::class)->apply();

        $seen = config($key->configPath());

        if (! matchesStored($key, $stored, $seen)) {
            $ignored[] = $key->value . ' -> ' . $key->configPath()
                . ' (stored "' . $stored . '", config read ' . var_export($seen, true) . ')';
        }

        $repository->clear($key, null);
        StoredSettingsOverlay::forget();
    }

    expect($ignored)->toBe([]);
});

it('leaves a key nobody has stored at its configured default', function (): void {
    // An installation that configures everything by environment must behave exactly as it did. The overlay
    // is an overlay: a key with no row is not set to null, it is left alone.
    config(['documents.rate_limits.upload_per_subject.max_attempts' => 30]);

    app(StoredSettingsOverlay::class)->apply();

    expect(config('documents.rate_limits.upload_per_subject.max_attempts'))->toBe(30);
});

it('reads an emptied setting as "use the default" rather than as an empty value', function (): void {
    // A cleared field is somebody withdrawing a choice. An empty string reaching a URL or a model name
    // would be a working configuration pointing nowhere.
    $repository = app(SettingsRepository::class);
    $repository->set(SettingKey::DOCUMENTS_UPLOAD_MAX_ATTEMPTS, '', null);
    StoredSettingsOverlay::forget();

    app(StoredSettingsOverlay::class)->apply();

    expect(config('documents.rate_limits.upload_per_subject.max_attempts'))->toBeNull();
});

it('takes effect on the next read after a save, without waiting for a cache to expire', function (): void {
    $repository = app(SettingsRepository::class);

    $repository->set(SettingKey::DOCUMENTS_UPLOAD_MAX_ATTEMPTS, '30', null);
    app(StoredSettingsOverlay::class)->apply();
    expect(config('documents.rate_limits.upload_per_subject.max_attempts'))->toBe(30);

    // No `forget()` here on purpose: saving is what must clear the cache, not the caller remembering to.
    $repository->set(SettingKey::DOCUMENTS_UPLOAD_MAX_ATTEMPTS, '100', null);
    app(StoredSettingsOverlay::class)->apply();

    expect(config('documents.rate_limits.upload_per_subject.max_attempts'))->toBe(100);
});

it('ignores a row whose key this version no longer has', function (): void {
    // A downgrade would want it back, and nothing reads it meanwhile — so it is skipped, not deleted, and
    // certainly not fatal.
    \Illuminate\Support\Facades\DB::table('settings')->insert([
        'key' => 'something.removed_last_year',
        'is_secret' => false,
        'value_plain' => '7',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
    StoredSettingsOverlay::forget();

    app(StoredSettingsOverlay::class)->apply();
})->throwsNoExceptions();

/** A value this key would accept, in the shape a form would submit. */
function storableValueFor(SettingKey $key): string
{
    return match ($key->type()) {
        SettingType::BOOLEAN => '1',
        SettingType::DECIMAL => '0.25',
        SettingType::URL => 'https://example.test/feed',
        SettingType::STRING => $key === SettingKey::SECURITY_HASH_DRIVER ? 'argon2id' : 'kern-test-value',
        default => '7',
    };
}

/** Whether what config returned is the stored value, cast as this key's type says it should be. */
function matchesStored(SettingKey $key, string $stored, mixed $seen): bool
{
    return match ($key->type()) {
        SettingType::BOOLEAN => $seen === true,
        SettingType::DECIMAL => is_float($seen) && abs($seen - (float) $stored) < 0.0001,
        SettingType::INTEGER => $seen === (int) $stored,
        default => $seen === $stored,
    };
}
