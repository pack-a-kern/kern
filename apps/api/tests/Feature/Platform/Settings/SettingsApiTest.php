<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Tests\TestCase;

/**
 * `$response->json()` is `mixed`; these narrow it once so each test reads plainly and PHPStan has
 * something concrete to check.
 *
 * @return array<string, mixed>
 */
function settingRow(TestCase $test, User $admin, string $group, string $key): array
{
    /** @var array<int, array{group: string, settings: array<int, array<string, mixed>>}> $groups */
    $groups = $test->actingAs($admin)->getJson('/api/v1/settings')->json('groups');

    foreach ($groups as $candidate) {
        if ($candidate['group'] !== $group) {
            continue;
        }

        foreach ($candidate['settings'] as $setting) {
            if (($setting['key'] ?? null) === $key) {
                return $setting;
            }
        }
    }

    throw new RuntimeException("Setting {$key} not found in group {$group}.");
}

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    $this->admin = settingsAdminUser();
});

it('returns every group with provenance for an administrator', function (): void {
    $response = $this->actingAs($this->admin)->getJson('/api/v1/settings')->assertOk();

    /** @var array<int, array{group: string}> $groups */
    $groups = $response->json('groups');

    expect(array_column($groups, 'group'))
        ->toEqualCanonicalizing(['ai', 'documents', 'sources', 'fx', 'spv', 'security']);

    $window = settingRow($this, $this->admin, 'spv', 'spv.messages_window_days');

    expect($window['source'])->toBe('inherited')
        ->and($window['read_only'])->toBeFalse()
        ->and($window)->toHaveKey('config_path');
});

it('refuses the settings API to a non-administrator', function (): void {
    $this->actingAs(ordinaryUser())->getJson('/api/v1/settings')->assertForbidden();
});

it('stores an override and reports it as a database value', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/spv', ['settings' => ['spv.messages_window_days' => 90]])
        ->assertOk()
        ->assertJsonPath('applied.0', 'spv.messages_window_days');

    expect(settingRow($this, $this->admin, 'spv', 'spv.messages_window_days'))
        ->toMatchArray(['source' => 'database', 'value' => '90']);
});

it('clears an override with an explicit null, restoring the inherited value', function (): void {
    Config::set(SettingKey::SPV_MESSAGES_WINDOW_DAYS->configPath(), 50);
    app(SettingsRepository::class)->set(SettingKey::SPV_MESSAGES_WINDOW_DAYS, '90', null);

    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/spv', ['settings' => ['spv.messages_window_days' => null]])
        ->assertOk();

    expect(settingRow($this, $this->admin, 'spv', 'spv.messages_window_days'))
        ->toMatchArray(['source' => 'inherited', 'value' => '50']);
});

it('refuses a read-only setting on the server, not just in the UI', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/fx', ['settings' => ['fx.decimal_scale' => 2]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.fx.decimal_scale']);

    expect(app(SettingsRepository::class)->get(SettingKey::FX_DECIMAL_SCALE))->toBeNull();
});

it('rejects a setting that does not belong to the group it was sent to', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/spv', ['settings' => ['ai.model' => 'anything']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.ai.model']);
});

it('rejects a malformed value and applies nothing else from that group', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/spv', ['settings' => [
            'spv.messages_window_days' => 'not-a-number',
            'browser_worker.timeout_seconds' => 30,
        ]])
        ->assertStatus(422);

    // The valid sibling must not have been applied — a partly-applied group is harder to reason
    // about than a rejected one.
    expect(app(SettingsRepository::class)->get(SettingKey::BROWSER_WORKER_TIMEOUT_SECONDS))->toBeNull();
});

it('returns 404 for an unknown group rather than silently accepting it', function (): void {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/group/nonsense', ['settings' => []])
        ->assertNotFound();
});
