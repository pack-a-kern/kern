<?php

declare(strict_types=1);

namespace App\Domain\Settings\DataTransferObjects;

use App\Domain\Settings\Enums\SettingKey;
use App\Domain\Settings\Enums\SettingSource;
use App\Domain\Settings\Enums\SettingType;

/**
 * One setting as a screen needs it: the effective value, where that value came from, and whether it
 * may be changed at all.
 *
 * `value` is null for a secret — a secret is never returned (`docs/handoff/041-settings-module.md`);
 * `hasValue` and `hint` say whether one is set without disclosing it.
 */
final readonly class ResolvedSetting
{
    public function __construct(
        public SettingKey $key,
        public SettingType $type,
        public SettingSource $source,
        public ?string $value,
        public bool $hasValue,
        public ?string $hint,
        public bool $readOnly,
        public string $configPath,
        public ?string $updatedAt = null,
        public ?string $updatedBy = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'group' => $this->key->group()->value,
            'type' => $this->type->value,
            'source' => $this->source->value,
            'value' => $this->value,
            'has_value' => $this->hasValue,
            'hint' => $this->hint,
            'read_only' => $this->readOnly,
            'config_path' => $this->configPath,
            'updated_at' => $this->updatedAt,
            'updated_by' => $this->updatedBy,
        ];
    }
}
