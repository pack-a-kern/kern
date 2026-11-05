<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Services;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\DataTransferObjects\NotificationType;
use Kern\Platform\Notifications\Models\NotificationPreference;

/**
 * Whether a person wants a kind of notification on their devices: what they chose, or the kind's default.
 */
final readonly class NotificationPreferences
{
    public function __construct(private NotificationTypeRegistry $types)
    {
    }

    /** Never for a kind nobody declared, nor one declared for a permission the person lacks. */
    public function allowsPush(User $user, string $typeKey): bool
    {
        $type = $this->types->find($typeKey);

        if ($type === null || ($type->permission !== null && ! $user->can($type->permission))) {
            return false;
        }

        return $this->chosen($user)[$typeKey] ?? $type->defaultOn;
    }

    /**
     * Every kind the person can receive, with whether each is on for them.
     *
     * @return list<array{type: NotificationType, enabled: bool}>
     */
    public function forUser(User $user): array
    {
        $chosen = $this->chosen($user);

        return array_map(
            static fn (NotificationType $type): array => ['type' => $type, 'enabled' => $chosen[$type->key] ?? $type->defaultOn],
            $this->types->forUser($user),
        );
    }

    public function set(User $user, string $typeKey, bool $enabled): void
    {
        NotificationPreference::query()->upsert(
            [['user_id' => $user->id, 'type' => $typeKey, 'push_enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]],
            ['user_id', 'type'],
            ['push_enabled', 'updated_at'],
        );
    }

    /** @return array<string, bool> */
    private function chosen(User $user): array
    {
        /** @var array<string, bool> */
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->pluck('push_enabled', 'type')
            ->map(static fn (mixed $value): bool => (bool) $value)
            ->all();
    }
}
