<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\DataTransferObjects\AiProviderCredentials;
use Kern\Platform\Settings\DataTransferObjects\AiTaskModel;
use Kern\Platform\Settings\Enums\SettingKey;
use RuntimeException;

/**
 * Resolves, for each task named in `config/ai/tasks.php`, the model it will actually use — its own
 * when one is configured, otherwise the installation's base `ai.model` ({@see
 * ResolveAiProviderCredentials}).
 *
 * The single answer to "which model does each kind of AI work use", read by the settings screen to
 * list them and by {@see \Kern\Platform\Http\Controllers\Settings\VerifyAiTaskModelsController} to
 * ask the provider whether those models exist. Resolved fresh on every call, like every other
 * settings read, so a model saved on the screen is reflected at once.
 */
final readonly class AiTaskModels
{
    public function __construct(
        private ConfigRepository $config,
        private ResolveAiProviderCredentials $resolveCredentials,
        private ResolveTaskModel $resolveTaskModel,
    ) {
    }

    /**
     * Pass already-resolved credentials to reuse them for the base model; otherwise they are
     * resolved here. The verify endpoint resolves them once to reach the provider and hands them in,
     * so the base model is not resolved a second time.
     *
     * @return list<AiTaskModel>
     */
    public function all(?AiProviderCredentials $credentials = null): array
    {
        $tasks = $this->config->get('ai.tasks');

        if (! is_array($tasks)) {
            throw new RuntimeException('ai.tasks is missing or is not an array.');
        }

        $base = $this->baseModel($credentials);

        /** @var list<array{id: string, definition: array<array-key, mixed>}> $entries */
        $entries = [];
        /** @var array<string, SettingKey> $keys */
        $keys = [];

        foreach ($tasks as $id => $definition) {
            if (! is_string($id) || ! is_array($definition)) {
                throw new RuntimeException('Each ai.tasks entry must be keyed by a string and hold an array.');
            }

            $entries[] = ['id' => $id, 'definition' => $definition];

            $settingKey = $definition['setting_key'] ?? null;

            if (is_string($settingKey)) {
                $key = SettingKey::tryFrom($settingKey);

                if ($key !== null) {
                    $keys[$settingKey] = $key;
                }
            }
        }

        // Every task's override in one read of the settings table, not one query per task.
        $models = $this->resolveTaskModel->many(array_values($keys));

        return array_map(
            fn (array $entry): AiTaskModel => $this->resolve($entry['id'], $entry['definition'], $base, $models),
            $entries,
        );
    }

    /**
     * The distinct, non-null model names across all tasks — what the provider is asked about, each
     * name once however many tasks share it.
     *
     * @return list<string>
     */
    public function distinctModels(?AiProviderCredentials $credentials = null): array
    {
        $models = [];

        foreach ($this->all($credentials) as $task) {
            if ($task->model !== null) {
                $models[$task->model] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @param  array<string, ?string>  $models  each known setting key's resolved model, from {@see ResolveTaskModel::many()}
     */
    private function resolve(string $id, array $definition, ?string $base, array $models): AiTaskModel
    {
        $settingKey = $definition['setting_key'] ?? null;
        $optional = (bool) ($definition['optional'] ?? false);

        // No model of its own — or a key that is not (or no longer) a known setting, a mistyped
        // config value: either way the task follows the base model. Degrading a bad key to the
        // default keeps one wrong arm from blanking the whole card, and the test suite still catches
        // the typo because the task's resolved model would then be wrong, not merely inherited.
        if (! is_string($settingKey) || ! array_key_exists($settingKey, $models)) {
            return new AiTaskModel($id, $base, true, null, false);
        }

        // The override if one is saved, else the task's env default — the same resolution the
        // service that runs the task uses, so the screen and the work never disagree.
        $own = $models[$settingKey];

        if ($own !== null) {
            return new AiTaskModel($id, $own, false, $settingKey, $optional);
        }

        // Unset: off when the task is optional (a second reader nobody asked for), otherwise the
        // base model, which is the fallback every reader shares.
        return $optional
            ? new AiTaskModel($id, null, false, $settingKey, $optional)
            : new AiTaskModel($id, $base, true, $settingKey, $optional);
    }

    private function baseModel(?AiProviderCredentials $credentials = null): ?string
    {
        $model = ($credentials ?? ($this->resolveCredentials)())->model;

        return $model !== null && $model !== '' ? $model : null;
    }
}
