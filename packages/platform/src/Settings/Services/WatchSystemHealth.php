<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Kern\Platform\Settings\DataTransferObjects\ComponentHealth;
use Kern\Platform\Settings\Events\SystemHealthChanged;

/**
 * Which services are down, as of the last scheduled look — and the push when that changes.
 *
 * `ProbeSystemHealth` asks every service, which is too slow and too loud to do on every page. So the
 * scheduler runs {@see self::watch()} (`kern:health:watch`, every few minutes) and the result is kept;
 * every page reads the kept answer ({@see self::down()}), which costs one cache read, and the people
 * watching hear about a change the moment the scheduler sees it, over the WebSocket. No screen polls.
 *
 * Only a change is broadcast. A service that stays down is said once, not every five minutes.
 */
final readonly class WatchSystemHealth
{
    public const string CACHE_KEY = 'system-health:down';

    /**
     * What the scheduled look leaves out, because it cannot see it from where it runs.
     *
     * The watch runs as a task, in the queue worker, and the browser worker sits on a network of its
     * own (`browser_net`) that only the web process joins — it has a way out to the internet, and the
     * workers holding the keys are kept off it on purpose (`compose.yaml`). From the queue it never
     * answers, so counting it reported it down at every look while it was fine. It stays on the status
     * screen, which probes from the web process, and the SPV screen says so itself when it is down.
     */
    private const array UNREACHABLE_FROM_WORKERS = ['browser_worker'];

    public function __construct(
        private ProbeSystemHealth $probe,
        private CacheRepository $cache,
        private Dispatcher $events,
    ) {
    }

    /**
     * Probes, keeps the answer, and broadcasts it when it differs from the one kept before.
     *
     * @return list<string> the keys of the services not answering now
     */
    public function watch(): array
    {
        return $this->record(($this->probe)());
    }

    /**
     * Keeps what `$components` says is down, and broadcasts it when that is not what was kept before.
     *
     * @param  list<ComponentHealth>  $components  one probe's answer
     * @return list<string> the keys of the services not answering
     */
    public function record(array $components): array
    {
        $down = [];

        foreach ($components as $component) {
            if ($component->state === 'degraded' && ! in_array($component->key, self::UNREACHABLE_FROM_WORKERS, true)) {
                $down[] = $component->key;
            }
        }

        sort($down);

        $before = $this->down();
        $this->cache->forever(self::CACHE_KEY, $down);

        if ($before !== $down) {
            $this->events->dispatch(new SystemHealthChanged($down));
        }

        return $down;
    }

    /** @return list<string> the services that were down at the last look; none before the first */
    public function down(): array
    {
        $kept = $this->cache->get(self::CACHE_KEY);

        return is_array($kept) ? array_values(array_filter($kept, is_string(...))) : [];
    }
}
