<?php

declare(strict_types=1);

namespace Kern\Documents\Tests\Unit\ClamdVirusScannerUnavailableTest;

use Kern\Documents\Scanning\ClamdVirusScanner;
use Kern\Documents\Scanning\Exceptions\VirusScannerUnavailableException;
use RuntimeException;

/**
 * `docs/AGENTS.md` §21.4 / `BLUEPRINT.md` §14.2 "a scan failure or an unreachable scanner must block
 * the document, never fail open" — the scanner-client half of that guarantee. No live ClamAV is
 * needed for this: an unreachable host proves the fail-closed contract on its own. The EICAR
 * detection half (a scanner that *is* reachable and finds something) is exercised against a real
 * daemon in `tests/Feature/Documents/ScanQuarantinedDocumentJobTest.php`'s live-ClamAV test.
 */
/** @return resource */
function streamOf(string $content)
{
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

it('throws, never reports clean, when the daemon cannot be reached at all', function (): void {
    // Port 1 is a reserved, always-refused TCP port on any host — no live service needed to
    // prove "connection refused" is treated as unavailable, not as a clean verdict.
    $scanner = new ClamdVirusScanner('127.0.0.1', 1, 1.0, 1.0, 65536);

    expect(fn () => $scanner->scan(streamOf('hello world')))->toThrow(VirusScannerUnavailableException::class);
});

it('throws, never reports clean, when the host does not resolve', function (): void {
    $scanner = new ClamdVirusScanner('this-host-does-not-exist.invalid', 3310, 1.0, 1.0, 65536);

    expect(fn () => $scanner->scan(streamOf('hello world')))->toThrow(VirusScannerUnavailableException::class);
});

it('throws promptly rather than waiting indefinitely, whether the address times out or is refused immediately', function (): void {
    // 10.255.255.1 is a non-routable address in most environments (connect timeout); some
    // sandboxes reject it immediately instead (connection refused). Either failure mode must
    // throw within the configured bound, never report clean.
    $scanner = new ClamdVirusScanner('10.255.255.1', 3310, 0.5, 0.5, 65536);
    $start = microtime(true);

    expect(fn () => $scanner->scan(streamOf('hello world')))->toThrow(VirusScannerUnavailableException::class);
    expect(microtime(true) - $start)->toBeLessThan(5.0);
});
