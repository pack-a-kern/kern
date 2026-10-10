<?php

declare(strict_types=1);

namespace Kern\Documents\Scanning;

use Kern\Documents\Scanning\Exceptions\VirusScannerUnavailableException;
use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;

/**
 * Lets the platform's uploads reach this package's ClamAV scanner.
 *
 * The adapter half of {@see UploadScanner}. `kern/platform` declares that port because it cannot import
 * from here — `kern/documents` requires `kern/platform`, and the reverse would be a cycle — so the
 * implementation lives on this side, where {@see VirusScanner} is already bound to clamd.
 *
 * It translates, and decides nothing. An unavailable scanner arrives as an exception and leaves as one;
 * the fail-closed rule is {@see VirusScanner}'s and is simply carried across the boundary intact.
 */
final readonly class ScannerBackedUploadScanner implements UploadScanner
{
    public function __construct(private VirusScanner $scanner)
    {
    }

    public function scanOrReject($stream): void
    {
        try {
            $outcome = $this->scanner->scan($stream);
        } catch (VirusScannerUnavailableException $e) {
            throw UploadRejectedException::scannerUnavailable($e->getMessage());
        }

        if ($outcome === ScanOutcome::INFECTED) {
            throw UploadRejectedException::infected();
        }
    }
}
