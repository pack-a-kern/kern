<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ClamdVirusScannerLiveTest;

use Kern\Documents\Scanning\ClamdVirusScanner;
use Kern\Documents\Scanning\ScanOutcome;
use RuntimeException;
use Tests\Fixtures\Documents\FileFixtures;

/**
 * `docs/AGENTS.md` §21.4 / `docs/handoff/022-document-pipeline.md`'s mandatory test: a real
 * ClamAV daemon flags the EICAR test string as infected, over the real INSTREAM wire protocol.
 *
 * Gated exactly the way `docs/handoff/012-encrypted-object-store.md`'s
 * `EncryptedObjectStoreLiveMinioTest` and `docs/handoff/005-crypto-core.md`'s
 * `OpenBaoLiveIntegrationTest` are: skipped unless `CLAMAV_TEST_HOST`/`CLAMAV_TEST_PORT` are both
 * set, using a dedicated env-var namespace rather than this app's own `documents.clamav.*`
 * config/`CLAMAV_HOST`/`CLAMAV_PORT` — the same reason handoff 012's decision #6 gives: a
 * throwaway test container may resolve the app's own default host without having a route to it,
 * and would then fail loudly instead of skipping cleanly.
 */
function liveClamavUnavailable(): bool
{
    return getenv('CLAMAV_TEST_HOST') === false || getenv('CLAMAV_TEST_PORT') === false;
}

function liveScanner(): ClamdVirusScanner
{
    $host = getenv('CLAMAV_TEST_HOST');
    $port = getenv('CLAMAV_TEST_PORT');

    return new ClamdVirusScanner(
        $host === false ? '' : $host,
        $port === false ? 0 : (int) $port,
        5.0,
        15.0,
        65536,
    );
}

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

it('reports a clean verdict for an ordinary document', function (): void {
    expect(liveScanner()->scan(streamOf(FileFixtures::minimalPdf())))->toBe(ScanOutcome::CLEAN);
})->skip(fn (): bool => liveClamavUnavailable(), 'Set CLAMAV_TEST_HOST/CLAMAV_TEST_PORT to run against a live ClamAV daemon.');

it('detects the EICAR test string as infected — proving real AV detection, not a mocked verdict', function (): void {
    expect(liveScanner()->scan(streamOf(FileFixtures::eicarTestString())))->toBe(ScanOutcome::INFECTED);
})->skip(fn (): bool => liveClamavUnavailable(), 'Set CLAMAV_TEST_HOST/CLAMAV_TEST_PORT to run against a live ClamAV daemon.');
