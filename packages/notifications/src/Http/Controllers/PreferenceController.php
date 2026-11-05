<?php

declare(strict_types=1);

namespace Kern\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Notifications\Http\Person;
use Kern\Notifications\Services\NoticeTypes;
use Kern\Notifications\Services\Preferences;
use Kern\Notifications\Services\PushSender;

/**
 * `GET` and `PATCH /preferences` — whether this server sends to devices at all, the key a browser
 * subscribes with, and one switch per registered kind, named in the page's language.
 */
final readonly class PreferenceController
{
    public function __construct(
        private Preferences $preferences,
        private NoticeTypes $types,
        private PushSender $sender,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $locale = Person::locale($request);
        $enabled = $this->preferences->forNotifiable(Person::of($request));
        $types = [];

        foreach ($this->types->all() as $key => $type) {
            $types[] = [
                'key' => $key,
                'label' => $type->label($locale),
                'hint' => $type->hint($locale),
                'enabled' => $enabled[$key],
            ];
        }

        return response()->json([
            'configured' => $this->sender->configured(),
            'public_key' => $this->sender->configured() ? $this->sender->publicKey() : null,
            'types' => $types,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($this->types->keys())],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->preferences->set(Person::of($request), $data['type'], (bool) $data['enabled']);

        return response()->json(['type' => $data['type'], 'enabled' => (bool) $data['enabled']]);
    }
}
