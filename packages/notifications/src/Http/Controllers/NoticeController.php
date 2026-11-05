<?php

declare(strict_types=1);

namespace Kern\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\Notifications\Http\Person;
use Kern\Notifications\Models\StoredNotice;
use Kern\Notifications\Services\NoticeTypes;

/**
 * `GET /` and `POST /read` — the bell: the person's unread notices, in their words; then all of them
 * seen, and gone from it. A read notice is not shown again.
 *
 * Written here, on each request, by each notice's kind: the table keeps ids only, and the page shows
 * what it is given without knowing one kind from another. A notice whose kind is no longer registered,
 * or which its kind will not show this person, is left out. Once everything is read, nothing is
 * written at all.
 */
final readonly class NoticeController
{
    public function __construct(private NoticeTypes $types)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $person = Person::of($request);
        $locale = Person::locale($request);

        $unread = StoredNotice::query()
            ->whereMorphedTo('notifiable', $person)
            ->whereNull('read_at')
            ->latest()
            ->limit(config()->integer('notifications.bell_limit', 20))
            ->get();

        // Each kind writes all of its notices at once, so what it looks up it looks up once.
        $messages = [];

        foreach ($unread->groupBy('type') as $type => $ofType) {
            /** @var array<string, array<string, mixed>> $data */
            $data = $ofType->mapWithKeys(static fn (StoredNotice $notice): array => [$notice->id => $notice->data])->all();
            $messages += $this->types->render((string) $type, $data, $person, $locale);
        }

        $shown = [];

        foreach ($unread as $notice) {
            if (isset($messages[$notice->id])) {
                $shown[] = [
                    'id' => $notice->id,
                    'type' => $notice->type,
                    ...$messages[$notice->id]->toArray(),
                    'created_at' => $notice->created_at?->toIso8601String(),
                ];
            }
        }

        // What is shown, not what is stored: a notice left out must not light the bell.
        return response()->json(['notifications' => $shown, 'unread' => count($shown)]);
    }

    public function markRead(Request $request): Response
    {
        StoredNotice::query()
            ->whereMorphedTo('notifiable', Person::of($request))
            ->whereNull('read_at')
            ->update(['read_at' => now('UTC')]);

        return response()->noContent();
    }
}
