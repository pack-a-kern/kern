<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Testing\InMemoryKeyManagementService;

/**
 * The exit code is the whole point of this command: `scripts/install.sh` ends on it, and a non-zero
 * exit is what stops an install from reporting success over a service that never answered.
 *
 * So every probe is pinned to a known answer here, including the two that would otherwise depend on
 * whether a real Redis and a real clamd happen to be reachable from wherever the suite is running —
 * in the application container they are, in CI they are not, and a test whose result depends on
 * that is worth nothing.
 */
beforeEach(function (): void {
    Storage::fake(Config::string('filesystems.default'));
    Http::preventStrayRequests();

    // Both optional services off, and nothing faked with a `*` pattern: the first matching stub
    // wins, so a wildcard registered here could never be overridden by a test below.
    Config::set('services.processing.base_url', null);
    Config::set('browser_worker.url', null);

    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    Redis::shouldReceive('connection')->andReturn(Mockery::mock(['command' => true]));

    // A second is plenty against 127.0.0.1, and keeps a refused probe from holding the suite for
    // the three seconds the health page allows itself.
    Config::set('operations.health.timeout_seconds', 1);
});

/**
 * A loopback port nothing is listening on, so `fsockopen` is refused at once rather than waiting
 * out its timeout. Asked of the kernel rather than hard-coded: a port that happens to be in use on
 * the machine running the suite would make the antivirus row healthy-ish for the wrong reason.
 */
function portNobodyIsListeningOn(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');

    if ($server === false) {
        throw new RuntimeException('Could not open a socket to find a free port.');
    }

    $name = (string) stream_socket_get_name($server, false);
    fclose($server);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/** Points the antivirus probe at nothing, which is the one way to make that row fail on purpose. */
function withNoAntivirusListening(): void
{
    Config::set('documents.clamav.host', '127.0.0.1');
    Config::set('documents.clamav.port', portNobodyIsListeningOn());
}

it('prints one row per component, with the detail that was actually checked', function (): void {
    withNoAntivirusListening();

    Artisan::call('kern:health');
    $output = Artisan::output();

    // Every component ProbeSystemHealth returns. A probe added without a row here would be a
    // service the installer quietly stopped checking.
    foreach (['database', 'cache', 'key_management', 'object_storage', 'processing', 'antivirus', 'browser_worker'] as $key) {
        expect($output)->toContain($key);
    }

    // The detail is what makes a row actionable rather than decorative: this one names the KEK
    // version in use, which is where an operator sees that a rotation took.
    expect($output)->toContain('KEK version 1');
});

it('fails the install when a service this installation runs did not answer', function (): void {
    withNoAntivirusListening();

    $exitCode = Artisan::call('kern:health');

    // The component is named and the cause is not: an exception message from a probe can carry a
    // host or a DSN, and BLUEPRINT.md §4.10 keeps that in the log.
    expect($exitCode)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Did not answer: antivirus');
});

it('does not fail the install over a service this installation was told not to run', function (): void {
    withNoAntivirusListening();

    $exitCode = Artisan::call('kern:health');
    $output = Artisan::output();

    // `COMPOSE_FILE` is allowed to leave either of these out, so an installer that failed on one
    // would be failing on a decision the operator had just made deliberately. They are printed,
    // and they are not the reason.
    expect($output)->toContain('processing      not installed')
        ->and($output)->toContain('browser_worker  not installed')
        ->and($output)->toContain('Did not answer: antivirus')
        ->and($output)->not->toContain('Did not answer: processing')
        ->and($exitCode)->toBe(Command::FAILURE);
});

it('exits zero when every service this installation runs answered', function (): void {
    // clamd speaks its own line protocol, so being the healthy case means answering `zPING` with
    // `PONG`. A child process rather than a socket in this one: the probe blocks in `fread` waiting
    // for that reply, so nothing in this process could be there to send it.
    $port = portNobodyIsListeningOn();

    $stub = proc_open(
        [PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); while ($c = stream_socket_accept($s, 30)) { fwrite($c, "PONG\0"); fclose($c); }'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($stub)) {
        $this->markTestSkipped('No clamd stub could be started, so the all-healthy case is unreachable here.');
    }

    try {
        // Up to five seconds for the child to bind. It answers every connection rather than one, so
        // this readiness check does not consume the one the probe is about to make.
        $listening = false;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $readiness = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

            if ($readiness !== false) {
                fclose($readiness);
                $listening = true;

                break;
            }

            usleep(100_000);
        }

        if (! $listening) {
            $this->markTestSkipped('The clamd stub never started listening.');
        }

        Config::set('documents.clamav.host', '127.0.0.1');
        Config::set('documents.clamav.port', $port);

        $exitCode = Artisan::call('kern:health');

        expect($exitCode)->toBe(Command::SUCCESS)
            ->and(Artisan::output())->not->toContain('DOWN');
    } finally {
        proc_terminate($stub);
        proc_close($stub);
    }
});
