<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

/**
 * How settings are grouped on the administration screen. Each group saves independently, so a
 * rejected value in one group never blocks an unrelated change in another.
 */
enum SettingGroup: string
{
    case AI = 'ai';
    case DOCUMENTS = 'documents';
    case SOURCES = 'sources';
    case FX = 'fx';
    case SPV = 'spv';
    case SECURITY = 'security';

    /** @return list<SettingKey> */
    public function keys(): array
    {
        return array_values(array_filter(
            SettingKey::cases(),
            fn (SettingKey $key): bool => $key->group() === $this,
        ));
    }
}
