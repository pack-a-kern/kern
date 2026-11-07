<?php

declare(strict_types=1);

namespace Kern\Platform\Settings\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\Config\RequiredConfig;
use Kern\Platform\Http\StackService;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Settings\DataTransferObjects\ComponentHealth;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Live state of the services this installation actually runs, for Settings → System status.
 *
 * Every entry is the result of a real probe. Nothing here is inferred from configuration alone,
 * and no service we do not run is listed: a status board that reports "Healthy" without having
 * asked is worse than no status board, because it is trusted. Two answers are read from
 * configuration: `not_configured`, which claims nothing about a service and is only reached for one an
 * installation is free to leave out ({@see self::probeOptionalHttpService()}) or for mail written to
 * the log, where there is nothing to ask ({@see self::probeMail()}).
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
        private RequiredConfig $required,
        private Container $container,
    ) {
    }

    /**
     * @param  bool  $browserWorker  false for a caller off `browser_net`, which cannot reach the worker:
     *                                asking would only send its name, unresolved, to the host's DNS
     * @return list<ComponentHealth>
     */
    public function __invoke(bool $browserWorker = true): array
    {
        return array_values(array_filter([
            $this->probe('database', function (): string {
                DB::select('select 1');

                return $this->required->string('database.default');
            }),
            $this->probe('cache', function (): ?string {
                Redis::connection()->command('ping', []);

                return null;
            }),
            $this->probeKeyManagement(),
            $this->probe('object_storage', function (): string {
                $disk = $this->required->string('filesystems.default');

                // A read against a path that does not exist still exercises credentials, the
                // endpoint and the bucket — which is what "storage is reachable" means here.
                Storage::disk($disk)->fileExists('.health-probe');

                return $disk;
            }),
            // The one address every feature using the service reads, so "up" here means up for all
            // of them — not only for document extraction.
            $this->probeOptionalHttpService('processing', StackService::processing($this->config)),
            $this->probeAntivirus(),
            // Behind the worker's token check rather than at its `/health`, which answers the same with
            // a wrong token or none: a worker refusing this installation's token refuses every SPV run,
            // and looked healthy here until somebody tried one. Under BROWSER_AUTH_MODE=network it
            // answers without one, as it should.
            $browserWorker
                ? $this->probeOptionalHttpService('browser_worker', StackService::browserWorker($this->config), '/v1/stats', authenticated: true)
                : null,
            $this->probeMail(),
            $this->probeCertificates(),
        ]));
    }

    /**
     * The certificates the stack's own CA issued its services, read from the list `docker/certs`
     * publishes beside the CA — every service's name and when its certificate ends, and nothing a key
     * could be read from.
     *
     * Each is renewed long before it ends, so one with fewer than `crypto.tls.warn_days` left means the
     * renewal has stopped, and the row turns degraded while there is still time to find out why. Not
     * configured when the file is not named: an installation whose every service runs elsewhere has no
     * certificates of its own to watch.
     */
    private function probeCertificates(): ComponentHealth
    {
        $file = $this->config->get('crypto.tls.status_file');

        if (! is_string($file) || $file === '') {
            return ComponentHealth::notConfigured('certificates');
        }

        $nearest = null;

        $health = $this->probe('certificates', function () use ($file, &$nearest): string {
            $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

            if ($lines === false || $lines === []) {
                throw new RuntimeException('no certificate list');
            }

            foreach ($lines as $line) {
                [$name, $expiry] = array_pad(explode(' ', $line, 2), 2, '');
                $expires = new DateTimeImmutable($expiry);

                if ($nearest === null || $expires < $nearest[1]) {
                    $nearest = [$name, $expires];
                }
            }

            return sprintf('%d issued, the first to end is %s on %s', count($lines), $nearest[0], $nearest[1]->format('Y-m-d'));
        });

        $warnFrom = new DateTimeImmutable(sprintf('+%d days', $this->config->integer('crypto.tls.warn_days')));

        if ($health->state === 'healthy' && $nearest !== null && $nearest[1] < $warnFrom) {
            return ComponentHealth::degraded('certificates', $health->detail);
        }

        return $health;
    }

    /**
     * Whether mail leaves this server at all.
     *
     * `MAIL_MAILER=log` is the shipped default and looks like a working mailer: nothing fails, and
     * every message — the email-verification code among them — is written in full into the log file
     * instead of sent. With verification required, that leaves every new account held at a screen
     * whose code nobody receives, and anyone who can read the log able to confirm any address. So it is
     * reported rather than passed — as not configured, saying where the mail goes. Not as degraded:
     * the installer offers it on purpose for an installation with no mail server yet, and a degraded
     * row fails the install.
     *
     * An SMTP mailer is asked, the way every other row here asks: connected to and greeted, which is
     * what proves the host, the port and the TLS answer. Any other transport is named and not probed,
     * and says so.
     */
    private function probeMail(): ComponentHealth
    {
        $mailer = $this->required->string('mail.default');
        $transport = $this->required->string("mail.mailers.{$mailer}.transport");

        if (in_array($transport, ['log', 'array'], true)) {
            return ComponentHealth::notConfigured('mail', "{$mailer} — written to the log file, not sent");
        }

        return $this->probe('mail', function () use ($mailer, $transport): string {
            $symfony = Mail::mailer($mailer)->getSymfonyTransport();

            if (! $symfony instanceof EsmtpTransport) {
                return "{$transport} — delivery not checked";
            }

            $symfony->start();
            $symfony->stop();

            return "{$transport} {$this->required->string("mail.mailers.{$mailer}.host")}";
        });
    }

    /**
     * The key manager: reachable, holding the KEK, and still letting this installation in.
     *
     * The last part is the one that nearly failed unseen. The application's token had a 32-day life and
     * nothing renewed it; every check here passed until the day it would have lapsed and taken every
     * encrypted read with it. So the row also says how long the token has left, and turns degraded below
     * `crypto.kms.openbao.access_warn_days` — the bundled server replaces it at seven, so reaching three
     * means its daily check has stopped.
     */
    private function probeKeyManagement(): ComponentHealth
    {
        $remaining = null;

        $health = $this->probe('key_management', function () use (&$remaining): string {
            // Resolved here instead of injected, because *building* this is one of the things
            // that can be wrong: the binding reads its config and throws if any of it is
            // missing, and a constructor argument would turn that into a 500 from the one
            // endpoint an operator opens to find out what is broken. Inside the probe it is
            // a degraded row, which is what it is.
            $kms = $this->container->make(KeyManagementService::class);

            // A read of the key's metadata rather than a test encryption: it proves the same
            // address, token, transit mount and that the KEK itself exists — a sealed OpenBao
            // answers 503 and a missing key 404, both of which fail here — without asking
            // transit to wrap a data key nobody will ever hold. The version it returns is
            // worth reporting: after `kern:keys:rotate-kek` this row is where an operator sees
            // that the rotation took.
            $version = $kms->currentKekVersion();
            $remaining = $kms->accessSecondsRemaining();

            return $remaining === null
                ? sprintf('KEK version %d', $version)
                : sprintf('KEK version %d, access for %d more days', $version, intdiv($remaining, 86400));
        });

        $warnBelow = $this->config->integer('crypto.kms.openbao.access_warn_days') * 86400;

        if ($health->state === 'healthy' && is_int($remaining) && $remaining < $warnBelow) {
            return ComponentHealth::degraded('key_management', $health->detail);
        }

        return $health;
    }

    /**
     * One of the two services an installation is allowed not to run, asked over HTTP.
     *
     * `compose.services/python.yaml` and `compose.services/browser.yaml` are both files a
     * `COMPOSE_FILE` may leave out — the cost is AI features and SPV automation respectively, and
     * nothing else — so an address that was never set is a decision, not a fault, and reads as
     * `not_configured`. Degraded would send an operator hunting for a service they chose not to
     * install. Neither address carries a default for exactly this reason: where a service lives is
     * the installation's own fact, so "unset" is a reliable answer rather than a guess.
     *
     * One method for both, because the two probes are the same probe: an address from config, the CA
     * its certificate is checked against, a route, a status. They differ in which service, and in whether the route asked is
     * the unauthenticated `/health` or one behind the service's token — sent only then, because a
     * secret does not go where it buys nothing.
     *
     * The timeout is the health page's, never the service's own. `browser_worker.timeout_seconds` is
     * sized for the slowest page a playbook waits on (120 seconds by default), and a status board
     * that can hang for two minutes is not a status board.
     */
    private function probeOptionalHttpService(
        string $key,
        StackService $service,
        string $path = '/health',
        bool $authenticated = false,
    ): ComponentHealth {
        if (! $service->isConfigured()) {
            return ComponentHealth::notConfigured($key);
        }

        return $this->probe($key, function () use ($service, $path, $authenticated): ?string {
            $response = $service
                ->request($this->http, $this->config->integer('operations.health.timeout_seconds'), $authenticated)
                ->get($service->url($path));

            if ($response->failed()) {
                throw new RuntimeException('status ' . $response->status());
            }

            return null;
        });
    }

    /**
     * ClamAV speaks its own line protocol rather than HTTP: `zPING\0` is answered with `PONG\0`.
     * Anything else — including a connection that opens and stays silent, which is what a still-
     * loading freshclam looks like — is reported as degraded rather than assumed fine.
     */
    private function probeAntivirus(): ComponentHealth
    {
        return $this->probe('antivirus', function (): ?string {
            $host = $this->required->string('documents.clamav.host');
            $port = $this->required->positiveInt('documents.clamav.port');

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
