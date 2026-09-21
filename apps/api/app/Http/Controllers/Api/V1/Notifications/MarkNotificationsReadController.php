<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** `POST /api/v1/notifications/read` — everything in the bell has been seen. */
final class MarkNotificationsReadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $user->unreadNotifications()->update(['read_at' => now('UTC')]);

        return response()->noContent();
    }
}
