<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * `GET /api/v1/notifications` — the bell: this user's most recent notices, and how many are unread.
 *
 * The user's own, whichever taxpayer each is about: a notice follows the person, not the subject that
 * happens to be selected when they open the menu.
 */
final class ListNotificationsController extends Controller
{
    /** A menu, not an inbox: the recent ones are what it is for. */
    private const int LIMIT = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $notices = $user->notifications()->latest()->limit(self::LIMIT)->get();

        return response()->json([
            'notifications' => $notices->map(static fn (DatabaseNotification $notice): array => [
                'id' => $notice->id,
                'type' => $notice->type,
                'data' => $notice->data,
                'read_at' => $notice->read_at?->toIso8601String(),
                'created_at' => $notice->created_at?->toIso8601String(),
            ])->values()->all(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }
}
