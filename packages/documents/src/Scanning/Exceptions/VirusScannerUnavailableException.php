<?php

declare(strict_types=1);

namespace Kern\Documents\Scanning\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown for every failure mode of `Kern\Documents\Scanning\VirusScanner::scan()` other
 * than a clean/infected verdict: connection refused, DNS failure, timeout, an unexpected or
 * malformed clamd response, or the daemon reporting its own internal error. Callers must treat
 * this identically to "the scan found something" for the purpose of *not letting the document
 * proceed* — `BLUEPRINT.md` §21.4's "unreachable scanner blocks" requirement.
 */
final class VirusScannerUnavailableException extends RuntimeException
{
    public static function connectionFailed(string $host, int $port, ?Throwable $previous = null): self
    {
        return new self("Unable to connect to the ClamAV daemon at {$host}:{$port}.", 0, $previous);
    }

    public static function unexpectedResponse(): self
    {
        return new self('The ClamAV daemon returned a response this client does not recognize.');
    }

    public static function streamReadFailed(): self
    {
        return new self('Failed reading the document stream while sending it to ClamAV.');
    }

    public static function timedOut(string $host, int $port): self
    {
        return new self("Timed out communicating with the ClamAV daemon at {$host}:{$port}.");
    }
}
