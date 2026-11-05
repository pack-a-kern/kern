<?php

declare(strict_types=1);

namespace Kern\Platform\Http\Controllers\Notifications;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\DataTransferObjects\NotificationType;
use Kern\Platform\Notifications\Services\NotificationPreferences;
use Kern\Platform\Notifications\Services\NotificationTypeRegistry;
use Kern\Platform\Notifications\Services\PushSender;

/**
 * `GET` and `PATCH` `v1/notifications/preferences` — whether this installation sends device
 * notifications, the key a browser subscribes with, and one switch per kind the account can receive.
 *
 * Each kind's name and hint come in both languages from the package that declared it, so the page has
 * no list of its own: a kind a package adds appears as a switch with nothing else to change.
 */
final class NotificationPreferenceController
{
    /** The languages the interface offers, which every package's notification texts are written in. */
    private const array LOCALES = ['en', 'ro'];

    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly NotificationTypeRegistry $types,
        private readonly PushSender $sender,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'configured' => $this->sender->configured(),
            'public_key' => $this->sender->configured() ? $this->sender->publicKey() : null,
            'types' => array_map(
                fn (array $entry): array => [
                    'key' => $entry['type']->key,
                    'label' => $this->inEveryLanguage($entry['type']->labelKey),
                    'hint' => $this->inEveryLanguage($entry['type']->hintKey, $entry['type']->hintParameters),
                    'enabled' => $entry['enabled'],
                ],
                $this->preferences->forUser($user),
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $offered = array_map(static fn (NotificationType $type): string => $type->key, $this->types->forUser($user));

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($offered)],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->preferences->set($user, $data['type'], (bool) $data['enabled']);

        return response()->json(['type' => $data['type'], 'enabled' => (bool) $data['enabled']]);
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, string>
     */
    private function inEveryLanguage(string $key, array $parameters = []): array
    {
        $texts = [];

        foreach (self::LOCALES as $locale) {
            $text = __($key, $parameters, $locale);
            $texts[$locale] = is_string($text) ? $text : $key;
        }

        return $texts;
    }
}
