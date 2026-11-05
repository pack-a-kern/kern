<?php

declare(strict_types=1);

namespace Kern\Notifications\Tests\Fixtures;

/** The orders the example kind reads from — in memory, bound as a singleton by each test that needs one. */
final class Orders
{
    /** @param array<int, string> $carriers order number => carrier */
    public function __construct(public array $carriers = [])
    {
    }

    public function carrierOf(int $order): ?string
    {
        return $this->carriers[$order] ?? null;
    }
}
