<?php

declare(strict_types=1);

namespace Kern\Notifications\Models;

use Illuminate\Notifications\DatabaseNotification;

/**
 * A notice in the bell — Laravel's own notification row, written to the microsecond.
 *
 * The framework's model writes whole seconds whatever the column holds, and two notices about one
 * thing are routinely raised within the same second, so the bell would list them in whatever order
 * the database chose. Microseconds put them in the order they happened.
 */
final class StoredNotice extends DatabaseNotification
{
    /** @var string */
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
