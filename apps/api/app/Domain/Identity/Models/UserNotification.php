<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Notifications\DatabaseNotification;

/**
 * A notice in a user's bell — Laravel's own notification model, written to the microsecond.
 *
 * The framework's model writes whole seconds whatever the column holds, and two notices about one
 * import are routinely raised within the same second — read, then written straight after — so the
 * bell listed them in whatever order the database chose. Microseconds put them in the order they
 * happened.
 */
final class UserNotification extends DatabaseNotification
{
    /** @var string */
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
