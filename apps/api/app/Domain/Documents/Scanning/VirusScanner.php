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
}
