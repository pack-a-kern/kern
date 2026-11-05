<?php

declare(strict_types=1);

namespace Kern\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Notifications\DataTransferObjects\Message;
use Kern\Notifications\Http\Person;
use Kern\Notifications\Services\PushSender;

/**
 * `POST /push/test` — one notice to every device of the signed-in person, so they can see that notices
 * arrive. Not a kind of notice and not kept in the bell: it is asked for, not subscribed to. Throttled,
 * since each is a request to a push service.
 */
final class SendTestPushController
{
    public function __invoke(Request $request, PushSender $sender): JsonResponse
    {
        if (! $sender->configured()) {
            return response()->json(['message' => __('notifications::notifications.not_configured')], 409);
        }

        $delivered = $sender->send(Person::of($request), static fn (string $locale): Message => new Message(
            title: __('notifications::notifications.test.title', [], $locale),
            body: __('notifications::notifications.test.body', [], $locale),
            tag: 'notifications-test',
        ));

        return response()->json(['delivered' => $delivered]);
    }
}
