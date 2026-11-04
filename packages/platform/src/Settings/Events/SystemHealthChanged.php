<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The set of services that are not answering changed — broadcast to the people who may read the
 * system status, so the app's top strip can say so without anyone asking.
 *
 * On `system.health`, which only `system.settings.view` may join (`routes/channels.php`). The payload
 * is the services' keys and nothing else: no detail, no host, no exception text. Whoever needs more
 * opens Settings → System status, which probes again under its own permission.
 */
final class SystemHealthChanged implements ShouldBroadcast
{
    use Dispatchable;

    public const string CHANNEL = 'system.health';

    /** @param list<string> $down the keys of the services not answering, as `ComponentHealth` names them */
    public function __construct(public readonly array $down)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return 'SystemHealthChanged';
    }

    /** @return array{down: list<string>} */
    public function broadcastWith(): array
    {
        return ['down' => $this->down];
    }
}
