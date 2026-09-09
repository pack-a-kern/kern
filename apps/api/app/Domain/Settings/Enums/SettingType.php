<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

/**
 * The value shape of a setting — drives both server-side validation and the input a settings
 * screen renders. `SECRET` is never returned to a client (`docs/handoff/041-settings-module.md`);
 * only whether one is set, and a last-four hint.
 */
enum SettingType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case URL = 'url';
    case SECRET = 'secret';
}
