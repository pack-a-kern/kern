<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

/**
 * End-to-end proof that config/logging.php's `tap` wiring actually redacts before anything
 * touches disk — not just that Kern\Platform\Logging\SensitiveDataProcessor works in isolation
 * (see tests/Unit/Support/Logging/SensitiveDataProcessorTest.php for that).
 *
 * Also proves processor ordering: the message uses a PSR-3 `{cnp}` placeholder, which
 * `PsrLogMessageProcessor` only substitutes *after* Kern\Platform\Logging\SensitiveDataProcessor has
 * already run (see that class's docblock) — so the substituted value in the formatted line is
 * `[REDACTED]`, not the raw CNP.
 */
test('the configured single log channel redacts sensitive context before it reaches disk', function (): void {
    $logFile = storage_path('logs/sensitive-redaction-feature-test.log');
    @unlink($logFile);

    config(['logging.channels.single.path' => $logFile]);

    Log::channel('single')->info('SPV login attempt for {cnp}', [
        'cnp' => '1900101221144',
        'password' => 'Sup3rSecret!',
        'iban' => 'RO49AAAA1B31007593840000',
        'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
    ]);

    expect(file_exists($logFile))->toBeTrue();

    $contents = (string) file_get_contents($logFile);

    expect($contents)->not->toContain('1900101221144')
        ->and($contents)->not->toContain('Sup3rSecret!')
        ->and($contents)->not->toContain('RO49AAAA1B31007593840000')
        ->and($contents)->not->toContain('eyJhbGciOiJIUzI1NiJ9')
        ->and($contents)->toContain('[REDACTED]')
        // The {cnp} placeholder was substituted with the already-redacted value, not the raw one.
        ->and($contents)->not->toContain('for 1900101221144');

    @unlink($logFile);
});
