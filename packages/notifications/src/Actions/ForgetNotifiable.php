<?php

declare(strict_types=1);

namespace Kern\Notifications\Actions;

use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\Models\Preference;
use Kern\Notifications\Notifications;

/**
 * A person is going: none of their devices is sent anything again, and their choices go with them.
 * For the application's own "delete this account" — there is no foreign key to do it on its own.
 */
final readonly class ForgetNotifiable
{
    public function __invoke(Model $notifiable): void
    {
        Notifications::devicesOf($notifiable)->delete();
        Preference::query()->whereMorphedTo('notifiable', $notifiable)->delete();
    }
}
