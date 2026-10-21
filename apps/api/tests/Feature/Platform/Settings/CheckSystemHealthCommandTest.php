<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;

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

    Redis::shouldReceive('connection')->andReturn(Mockery::mock(['command' => true]));

    // A second is plenty against 127.0.0.1, and keeps a refused probe from holding the suite for
    // the three seconds the health page allows itself.
    Config::set('operations.health.timeout_seconds', 1);
});

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
    foreach (['database', 'cache', 'key_management', 'object_storage', 'processing', 'antivirus', 'browser_worker', 'mail', 'certificates'] as $key) {
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
    // `PONG`. Mail is pointed at an SMTP stub that greets, so every row this case reports was asked.
    [$port, $clamd] = startStubServer(CLAMD_PONG);
    [$smtpPort, $smtp] = startStubServer(SMTP_GREETING);

    try {
        Config::set('documents.clamav.host', '127.0.0.1');
        Config::set('documents.clamav.port', $port);
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', '127.0.0.1');
        Config::set('mail.mailers.smtp.port', $smtpPort);

        $exitCode = Artisan::call('kern:health');

        expect($exitCode)->toBe(Command::SUCCESS)
            ->and(Artisan::output())->not->toContain('DOWN');
    } finally {
        stopStubServer($clamd);
        stopStubServer($smtp);
    }
});

/** A key manager that answers, holding KEK version 1, whose token has `$seconds` left. */
function keyManagerWithAccessFor(int $seconds): void
{
    $kms = Mockery::mock(KeyManagementService::class);
    $kms->shouldReceive('currentKekVersion')->andReturn(1);
    $kms->shouldReceive('accessSecondsRemaining')->andReturn($seconds);

    app()->instance(KeyManagementService::class, $kms);
}

it('says how many days the application token has left', function (): void {
    withNoAntivirusListening();
    keyManagerWithAccessFor(25 * 86400);

    Artisan::call('kern:health');

    expect(Artisan::output())->toMatch('/key_management\s+ok\s+KEK version 1, access for 25 more days/');
});

it('reports key management down once the token is about to lapse', function (): void {
    // The bundled server replaces the token with seven days left, so reaching the warning means its
    // daily check has stopped — and a lapsed token takes every encrypted read with it.
    withNoAntivirusListening();
    Config::set('crypto.kms.openbao.access_warn_days', 3);
    keyManagerWithAccessFor(2 * 86400);

    Artisan::call('kern:health');

    expect(Artisan::output())->toMatch('/key_management\s+DOWN\s+KEK version 1, access for 2 more days/')
        ->toContain('Did not answer: key_management');
});
