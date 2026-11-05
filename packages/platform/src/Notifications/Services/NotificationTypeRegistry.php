<?php

declare(strict_types=1);

namespace Kern\Platform\Notifications\Services;

use InvalidArgumentException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Notifications\DataTransferObjects\NotificationType;

/**
 * Every kind of device notification the installed packages send — the list the account page's switches
 * are drawn from. Each package declares its own kinds when it boots; none is written down anywhere else.
 */
final class NotificationTypeRegistry
{
    /** @var array<string, NotificationType> */
    private array $types = [];

    public function register(NotificationType $type): void
    {
        if (isset($this->types[$type->key])) {
            throw new InvalidArgumentException("Notification type [{$type->key}] is declared twice.");
        }

        $this->types[$type->key] = $type;
    }

    public function find(string $key): ?NotificationType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * The kinds `$user` can receive: all of them, less those declared for a permission they lack.
     *
     * @return list<NotificationType>
     */
    public function forUser(User $user): array
    {
        return array_values(array_filter(
            $this->types,
            static fn (NotificationType $type): bool => $type->permission === null || $user->can($type->permission),
        ));
    }
}
