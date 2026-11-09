<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

/**
 * How settings are grouped on the administration screen. Each group saves independently, so a
 * rejected value in one group never blocks an unrelated change in another.
 */
enum SettingGroup: string
{
    case AI = 'ai';
    case DOCUMENTS = 'documents';
    case SOURCES = 'sources';
    case BROWSER = 'browser';
    case LEGISLATION_PORTAL = 'legislation_portal';
    case FX = 'fx';
    case SPV = 'spv';
    case SECURITY = 'security';
    case BANKS = 'banks';

    /** @return list<SettingKey> */
    public function keys(): array
    {
        return array_values(array_filter(
            SettingKey::cases(),
            fn (SettingKey $key): bool => $key->group() === $this,
        ));
    }
}
