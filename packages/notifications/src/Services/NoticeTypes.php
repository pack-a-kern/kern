<?php

declare(strict_types=1);

namespace Kern\Notifications\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Kern\Notifications\Contracts\NoticeType;
use Kern\Notifications\DataTransferObjects\Message;

/**
 * The kinds of notice this application sends. Each package or the application registers its own when
 * it boots; the switches, the bell and the devices know a kind only from here.
 */
final class NoticeTypes
{
    /** @var array<string, class-string<NoticeType>> */
    private array $types = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** @param class-string<NoticeType> $type */
    public function register(string $type): void
    {
        if (! is_subclass_of($type, NoticeType::class)) {
            throw new InvalidArgumentException("[{$type}] is not a kind of notice.");
        }

        $key = $type::key();

        if (isset($this->types[$key])) {
            throw new InvalidArgumentException("Notice type [{$key}] is registered twice.");
        }

        $this->types[$key] = $type;
    }

    /** @return array<string, NoticeType> every kind, by key, in the order registered */
    public function all(): array
    {
        return array_map(fn (string $type): NoticeType => $this->resolve($type), $this->types);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /**
     * What stored notices of one kind say to `$notifiable` in `$locale` — none for a kind no longer
     * registered, and none for a notice its kind will not show them.
     *
     * @param  array<string, array<string, mixed>>  $notices  stored data, by notice id
     * @return array<string, Message> by notice id
     */
    public function render(string $key, array $notices, Model $notifiable, string $locale): array
    {
        $type = $this->types[$key] ?? null;

        return $type === null || $notices === [] ? [] : $this->resolve($type)->render($notices, $notifiable, $locale);
    }

    /** @param class-string<NoticeType> $type */
    private function resolve(string $type): NoticeType
    {
        $resolved = $this->container->make($type);
        assert($resolved instanceof NoticeType);

        return $resolved;
    }
}
