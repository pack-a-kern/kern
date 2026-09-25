<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Enums;

/**
 * Where a setting's *effective* value actually comes from.
 *
 * Shown on the settings screen for a concrete reason: without it, changing a value that is being
 * overridden elsewhere appears to do nothing, with no way to tell why. That exact failure cost an
 * hour of this project's time — `APP_DEBUG` was edited in one file while the running process took
 * its value from another, and nothing surfaced the discrepancy.
 */
enum SettingSource: string
{
    /** A row in the settings table. Editable here, and it wins. */
    case DATABASE = 'database';

    /** No override; the value comes from configuration (an env var, or a code default). */
    case INHERITED = 'inherited';
}
