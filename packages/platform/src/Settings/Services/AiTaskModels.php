<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\Settings\DataTransferObjects\AiTaskModel;
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
    ) {
    }

    /**
     * @return list<AiTaskModel>
     */
    public function all(): array
    {
        $tasks = $this->config->get('ai.tasks');

        if (! is_array($tasks)) {
            throw new RuntimeException('ai.tasks is missing or is not an array.');
        }

        $base = $this->baseModel();
        $resolved = [];

        foreach ($tasks as $id => $definition) {
            if (! is_string($id) || ! is_array($definition)) {
                throw new RuntimeException('Each ai.tasks entry must be keyed by a string and hold an array.');
            }

            $resolved[] = $this->resolve($id, $definition, $base);
        }

        return $resolved;
    }

    /**
     * The distinct, non-null model names across all tasks — what the provider is asked about, each
     * name once however many tasks share it.
     *
     * @return list<string>
     */
    public function distinctModels(): array
    {
        $models = [];

        foreach ($this->all() as $task) {
            if ($task->model !== null) {
                $models[$task->model] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * @param  array<array-key, mixed>  $definition
     */
    private function resolve(string $id, array $definition, ?string $base): AiTaskModel
    {
        $modelKey = $definition['model_key'] ?? null;

        // A task with no model of its own always uses the base model.
        if ($modelKey === null) {
            return new AiTaskModel($id, $base, true);
        }

        if (! is_string($modelKey) || $modelKey === '') {
            throw new RuntimeException("ai.tasks entry [{$id}] has a non-string or empty model_key.");
        }

        $configured = $this->config->get($modelKey);
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured !== '') {
            return new AiTaskModel($id, $configured, false);
        }

        // Unset: off when the task is optional (a second reader nobody asked for), otherwise the
        // base model, which is the fallback every reader shares.
        return (bool) ($definition['optional'] ?? false)
            ? new AiTaskModel($id, null, false)
            : new AiTaskModel($id, $base, true);
    }

    private function baseModel(): ?string
    {
        $model = ($this->resolveCredentials)()->model;

        return $model !== null && $model !== '' ? $model : null;
    }
}
