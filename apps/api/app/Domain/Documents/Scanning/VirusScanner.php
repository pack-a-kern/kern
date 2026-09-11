<?php

declare(strict_types=1);

namespace App\Domain\Documents\Scanning;

use App\Domain\Documents\Scanning\Exceptions\VirusScannerUnavailableException;

/**
 * `BLUEPRINT.md` §14.2/§3.1 ClamAV integration. The one hard rule every implementation must uphold,
 * because a caller's fail-closed logic depends on it: **a scan that could not be completed must
 * throw {@see VirusScannerUnavailableException}, never report {@see ScanOutcome::CLEAN}.**
 * "Unreachable scanner" and "scan error" are not distinguishable failure modes a caller needs —
 * both mean the same thing, "this document must not proceed."
 */
interface VirusScanner
{
    /**
     * @param resource $stream a readable stream of the file's bytes. The caller retains
     *                         ownership and must close it.
     *
     * @throws VirusScannerUnavailableException if the scanner could not be reached, timed out,
     *                                           or returned anything other than a clean/infected
     *                                           verdict.
     */
    public function scan($stream): ScanOutcome;

    /**
     * What this scanner is, and what it is running — recorded on `document_scans` so a past verdict
     * can be attributed to a specific engine and signature set.
     *
     * Unlike {@see self::scan()}, this must **never throw**. A scanner that answers a scan but not a
     * version query is still a working scanner, and failing an upload over unavailable metadata
     * would trade a document for a footnote. Unknown fields come back null, which is an honest
     * "not recorded" rather than a guess.
     */
    public function describe(): ScannerDescription;
}
