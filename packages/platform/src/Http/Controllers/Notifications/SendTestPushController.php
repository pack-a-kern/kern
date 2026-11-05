<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Notifications;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\Notifications\TestPushNotification;
use Kern\Platform\Notifications\Services\PushSender;

/**
 * `POST v1/notifications/push/test` — one notification to every device of the signed-in account, so a
 * person can see that they arrive. Throttled (`push-test-per-user`): each is a request to a push service.
 */
final class SendTestPushController
{
    public function __invoke(Request $request, PushSender $sender): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $sender->configured()) {
            return response()->json(['message' => __('platform::notifications.push.not_configured')], 409);
        }

        $test = new TestPushNotification();

        return response()->json(['delivered' => $sender->toUser($user, $test->toPush(...))]);
    }
}
