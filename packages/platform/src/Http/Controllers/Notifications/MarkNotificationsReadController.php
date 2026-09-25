<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Notifications;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\Platform\Identity\Models\User;

/** `POST /api/v1/notifications/read` — everything in the bell has been seen. */
final class MarkNotificationsReadController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->unreadNotifications()->update(['read_at' => now('UTC')]);

        return response()->noContent();
    }
}
