<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kern\Platform\Settings\Enums\SettingKey;
use Kern\Platform\Settings\Repositories\SettingsRepository;
use Kern\Platform\Settings\Services\ResolveTaskModel;

/**
 * @param  array<int, array<string, mixed>>  $tasks
 * @return array<string, array<string, mixed>>
 */
function keyByTaskId(array $tasks): array
{
    $byId = [];

    foreach ($tasks as $task) {
        $id = $task['id'] ?? null;

        if (is_string($id)) {
            $byId[$id] = $task;
        }
    }

    return $byId;
}

it('lists each task with its own model, or the base model it inherits', function (): void {
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_MODEL, 'base-model', null);

    // One task overrides, the rest inherit; the optional second reader is left off.
    config([
        'ai.statements.mapping.model' => null,
        'ai.statements.classification.model' => 'cls-model',
        'ai.idcard.read.model' => null,
        'ai.legislation.reader' => '',
        'ai.legislation.second_reader' => '',
    ]);

    $response = $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/settings/ai/task-models')
        ->assertOk();

    /** @var array<int, array<string, mixed>> $tasks */
    $tasks = $response->json('tasks');
    $byId = keyByTaskId($tasks);

    expect($byId['document_read'])->toMatchArray(['model' => 'base-model', 'uses_default' => true])
        ->and($byId['statement_mapping'])->toMatchArray(['model' => 'base-model', 'uses_default' => true])
        ->and($byId['statement_classification'])->toMatchArray(['model' => 'cls-model', 'uses_default' => false])
        ->and($byId['id_card'])->toMatchArray(['model' => 'base-model', 'uses_default' => true])
        ->and($byId['law_reader'])->toMatchArray(['model' => 'base-model', 'uses_default' => true])
        // Optional and unset: off, with no model, rather than inheriting the base one.
        ->and($byId['law_second_reader'])->toMatchArray(['model' => null, 'uses_default' => false]);
});

it('prefers a saved override to the env default, and names the setting key it is edited through', function (): void {
    config([
        'ai.statements.mapping.model' => 'env-mapping',
        'ai.statements.classification.model' => 'env-classification',
    ]);

    // One task is overridden on the settings screen; its neighbour is left on the env default.
    app(SettingsRepository::class)->set(SettingKey::AI_STATEMENT_MAPPING_MODEL, 'override-mapping', null);

    /** @var array<int, array<string, mixed>> $tasks */
    $tasks = $this->actingAs(settingsAdminUser())
        ->getJson('/api/v1/settings/ai/task-models')
        ->assertOk()
        ->json('tasks');
    $byId = keyByTaskId($tasks);

    expect($byId['statement_mapping'])->toMatchArray([
        'model' => 'override-mapping',
        'uses_default' => false,
        'setting_key' => 'ai.statement_mapping_model',
        'optional' => false,
    ])
        ->and($byId['statement_classification'])->toMatchArray([
            'model' => 'env-classification',
            'uses_default' => false,
            'setting_key' => 'ai.statement_classification_model',
        ])
        // The base-only reader carries no setting key of its own; the optional one is marked optional.
        ->and($byId['document_read'])->toMatchArray(['setting_key' => null])
        ->and($byId['law_second_reader'])->toMatchArray(['optional' => true]);
});

it('resolves a task model as the override, else the env default, else nothing', function (): void {
    $resolve = app(ResolveTaskModel::class);

    config(['ai.statements.mapping.model' => 'env-mapping', 'ai.idcard.read.model' => '']);

    expect($resolve(SettingKey::AI_STATEMENT_MAPPING_MODEL))->toBe('env-mapping')
        ->and($resolve(SettingKey::AI_ID_CARD_MODEL))->toBeNull();

    // A saved override wins over the env default, trimmed.
    app(SettingsRepository::class)->set(SettingKey::AI_STATEMENT_MAPPING_MODEL, '  override-mapping  ', null);

    expect($resolve(SettingKey::AI_STATEMENT_MAPPING_MODEL))->toBe('override-mapping');
});

it('saves and clears a per-task model override through the settings group endpoint', function (): void {
    config(['ai.statements.mapping.model' => 'env-mapping']);
    $admin = settingsAdminUser();
    $resolve = app(ResolveTaskModel::class);

    $this->actingAs($admin)
        ->putJson('/api/v1/settings/group/ai', ['settings' => ['ai.statement_mapping_model' => 'gpt-mapping']])
        ->assertOk();

    expect($resolve(SettingKey::AI_STATEMENT_MAPPING_MODEL))->toBe('gpt-mapping');

    // An explicit null clears the override; the env default returns.
    $this->actingAs($admin)
        ->putJson('/api/v1/settings/group/ai', ['settings' => ['ai.statement_mapping_model' => null]])
        ->assertOk();

    expect($resolve(SettingKey::AI_STATEMENT_MAPPING_MODEL))->toBe('env-mapping');
});

it('checks each task model against the provider and reports which it serves', function (): void {
    $admin = settingsAdminUser();
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_PROVIDER, 'openai', $admin->id);
    $settings->set(SettingKey::AI_BASE_URL, 'https://api.openai.com', $admin->id);
    $settings->set(SettingKey::AI_API_KEY, 'sk-saved', $admin->id);
    $settings->set(SettingKey::AI_MODEL, 'base-model', $admin->id);

    config(['ai.statements.classification.model' => 'cls-model']);

    // The provider serves the base model but not the classification override.
    Http::fake(['*' => Http::response(['data' => [['id' => 'base-model']]])]);

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/ai/task-models/verify')
        ->assertOk()
        ->assertJsonPath('checkable', true)
        ->assertJsonPath('exists.base-model', true)
        ->assertJsonPath('exists.cls-model', false);
});

it('says it could not check when nothing is configured, reaching no provider', function (): void {
    Http::fake();

    $this->actingAs(settingsAdminUser())
        ->postJson('/api/v1/settings/ai/task-models/verify')
        ->assertOk()
        ->assertJsonPath('checkable', false)
        ->assertJsonPath('reason', 'not_configured');

    Http::assertNothingSent();
});

it('says it could not check a provider that publishes no model list', function (): void {
    config(['ai.providers.presets.openai.models_path' => null]);

    $admin = settingsAdminUser();
    $settings = app(SettingsRepository::class);
    $settings->set(SettingKey::AI_PROVIDER, 'openai', $admin->id);
    $settings->set(SettingKey::AI_BASE_URL, 'https://api.openai.com', $admin->id);
    $settings->set(SettingKey::AI_API_KEY, 'sk-saved', $admin->id);

    Http::fake();

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/ai/task-models/verify')
        ->assertOk()
        ->assertJsonPath('checkable', false)
        ->assertJsonPath('reason', 'not_supported');

    Http::assertNothingSent();
});

it('keeps both task-model routes behind the settings permissions', function (): void {
    $user = ordinaryUser();

    Http::fake();

    $this->actingAs($user)->getJson('/api/v1/settings/ai/task-models')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/settings/ai/task-models/verify')->assertForbidden();

    Http::assertNothingSent();
});
