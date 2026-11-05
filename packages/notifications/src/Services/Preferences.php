<?php

declare(strict_types=1);

namespace Kern\Notifications\Services;

use Illuminate\Database\Eloquent\Model;
use Kern\Notifications\Models\Preference;

/** Whether a person wants a kind of notice on their devices: what they chose, and on otherwise. */
final readonly class Preferences
{
    public function __construct(private NoticeTypes $types)
    {
    }

    /** Never for a kind nobody registered. */
    public function allowsPush(Model $notifiable, string $key): bool
    {
        return $this->types->has($key) && ($this->chosen($notifiable)[$key] ?? true);
    }

    /** @return array<string, bool> every registered kind, by key, and whether it is on for this person */
    public function forNotifiable(Model $notifiable): array
    {
        $chosen = $this->chosen($notifiable);
        $keys = $this->types->keys();

        return array_combine($keys, array_map(static fn (string $key): bool => $chosen[$key] ?? true, $keys));
    }

    public function set(Model $notifiable, string $key, bool $enabled): void
    {
        Preference::query()->upsert(
            [[
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'type' => $key,
                'push_enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['notifiable_type', 'notifiable_id', 'type'],
            ['push_enabled', 'updated_at'],
        );
    }

    /** @return array<string, bool> */
    private function chosen(Model $notifiable): array
    {
        /** @var array<string, bool> */
        return Preference::query()
            ->whereMorphedTo('notifiable', $notifiable)
            ->pluck('push_enabled', 'type')
            ->map(static fn (mixed $value): bool => (bool) $value)
            ->all();
    }
}
