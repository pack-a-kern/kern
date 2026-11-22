<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\DataTransferObjects;

/**
 * One AI task and the model name it will use, as resolved from `config/ai/tasks.php` against the
 * installation's configured base model.
 *
 * `model` is the name that will actually be sent — the task's own, or the base `ai.model` it falls
 * back to — and `usesDefault` says which of those it is, so the screen can show a task that has no
 * model of its own as inheriting rather than as separately configured. `model` is null only when
 * the task is off (an optional task nobody configured) or when nothing is configured at all.
 *
 * Carries no secret: a model name is not a credential, and this is the shape the API returns.
 */
final readonly class AiTaskModel
{
    public function __construct(
        public string $id,
        public ?string $model,
        public bool $usesDefault,
    ) {
    }

    /**
     * @return array{id: string, model: string|null, uses_default: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'model' => $this->model,
            'uses_default' => $this->usesDefault,
        ];
    }
}
