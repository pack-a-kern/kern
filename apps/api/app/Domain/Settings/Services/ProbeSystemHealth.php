<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\DataTransferObjects\ComponentHealth;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Live state of the services this installation actually runs, for Settings → System status.
 *
 * Every entry is the result of a real probe. Nothing here is inferred from configuration alone,
 * and no service we do not run is listed: a status board that reports "Healthy" without having
 * asked is worse than no status board, because it is trusted.
 *
 * Deliberately not covered, rather than guessed at:
 *  - **Queue workers.** Horizon's supervisor state is not readable without Horizon's own storage
 *    contract, and a wrong worker count is actively misleading during an incident.
 *  - **Reverb.** A TCP connect proves a socket is open, not that broadcasting works; the
 *    dashboard's own connection indicator already reports the property that matters.
 *
 * Each probe is wrapped so one unreachable service degrades its own row and never fails the
 * request — this endpoint is most useful exactly when something is down.
 */
final readonly class ProbeSystemHealth
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @return list<ComponentHealth>
     */
    public function __invoke(): array
    {
        return [
            $this->probe('database', function (): string {
                DB::select('select 1');

                return $this->stringConfig('database.default');
            }),
            $this->probe('cache', function (): ?string {
                Redis::connection()->command('ping', []);

                return null;
            }),
            $this->probe('object_storage', function (): string {
                $disk = $this->stringConfig('filesystems.default');

                // A read against a path that does not exist still exercises credentials, the
                // endpoint and the bucket — which is what "storage is reachable" means here.
                Storage::disk($disk)->fileExists('.health-probe');

                return $disk;
            }),
            $this->probe('processing', function (): ?string {
                // The one address every feature using the service reads, so "up" here means up for all
                // of them — not only for document extraction.
                $baseUrl = $this->stringConfig('services.processing.base_url');
                $response = $this->http->timeout($this->config->integer('operations.health.timeout_seconds'))->get(rtrim($baseUrl, '/') . '/health');

                if ($response->failed()) {
                    throw new RuntimeException('status ' . $response->status());
                }

                return null;
            }),
            $this->probeAntivirus(),
        ];
    }

    /**
     * ClamAV speaks its own line protocol rather than HTTP: `zPING\0` is answered with `PONG\0`.
     * Anything else — including a connection that opens and stays silent, which is what a still-
     * loading freshclam looks like — is reported as degraded rather than assumed fine.
     */
    private function probeAntivirus(): ComponentHealth
    {
        return $this->probe('antivirus', function (): ?string {
            $host = $this->stringConfig('documents.clamav.host');
            $port = $this->intConfig('documents.clamav.port');

            $socket = @fsockopen($host, $port, $code, $message, $this->config->integer('operations.health.timeout_seconds'));

            if ($socket === false) {
                throw new RuntimeException('unreachable');
            }

            try {
                stream_set_timeout($socket, $this->config->integer('operations.health.timeout_seconds'));
                fwrite($socket, "zPING\0");
                $reply = fread($socket, 16);
            } finally {
                fclose($socket);
            }

            if (! is_string($reply) || ! str_starts_with($reply, 'PONG')) {
                throw new RuntimeException('no PONG');
            }

            return null;
        });
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("config [{$key}] is not a non-empty string");
        }

        return $value;
    }

    private function intConfig(string $key): int
    {
        $value = $this->config->get($key);

        if (! is_int($value)) {
            throw new RuntimeException("config [{$key}] is not an integer");
        }

        return $value;
    }

    /**
     * @param  callable(): (string|null)  $check
     */
    private function probe(string $key, callable $check): ComponentHealth
    {
        try {
            return ComponentHealth::healthy($key, $check());
        } catch (Throwable $exception) {
            // Exception messages from a driver can carry a DSN, a bucket name or a host; per
            // BLUEPRINT.md §4.10 none of that is forwarded to an HTTP response. The class name is
            // enough for an operator to know which layer failed, and the real cause is logged.
            report($exception);

            return ComponentHealth::degraded($key, class_basename($exception));
        }
    }
}
