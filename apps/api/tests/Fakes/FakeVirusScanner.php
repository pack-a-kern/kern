<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Documents\Scanning\Exceptions\VirusScannerUnavailableException;
use App\Domain\Documents\Scanning\ScannerDescription;
use App\Domain\Documents\Scanning\ScanOutcome;
use App\Domain\Documents\Scanning\VirusScanner;

/**
 * A scanner whose verdict the test chooses.
 *
 * One fake rather than an anonymous class per case: the three outcomes a caller must handle —
 * clean, infected, and *unreachable* — differ only in what `scan()` does, and repeating the rest of
 * the interface at each site is how a contract change turns into a scattered edit.
 *
 * {@see self::unavailable()} is the one worth naming. A scanner that cannot produce a verdict must
 * throw, never report `CLEAN`, and a fake that could only return outcomes would quietly make that
 * rule untestable.
 */
final class FakeVirusScanner implements VirusScanner
{
    private function __construct(
        private readonly ?ScanOutcome $outcome,
        private readonly ScannerDescription $description,
    ) {
    }

    public static function returning(ScanOutcome $outcome): self
    {
        // A plausible ClamAV version pair, so a test asserting on what was recorded is asserting on
        // a realistic shape rather than on a placeholder.
        return new self($outcome, new ScannerDescription('clamav', '1.5.4', '28118'));
    }

    /** A scanner that answers scans but refuses to say what it is — versions come back null. */
    public static function returningWithoutVersion(ScanOutcome $outcome): self
    {
        return new self($outcome, new ScannerDescription('clamav'));
    }

    /** A scanner that cannot be reached at all. */
    public static function unavailable(): self
    {
        return new self(null, new ScannerDescription('clamav'));
    }

    public function scan($stream): ScanOutcome
    {
        return $this->outcome ?? throw VirusScannerUnavailableException::connectionFailed('clamav', 3310);
    }

    public function describe(): ScannerDescription
    {
        return $this->description;
    }
}
