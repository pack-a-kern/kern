<?php

declare(strict_types=1);

namespace App\Domain\Documents\Scanning;

use App\Domain\Documents\Scanning\Exceptions\VirusScannerUnavailableException;
use InvalidArgumentException;

/**
 * `App\Domain\Documents\Scanning\VirusScanner` against a real `clamd` daemon over its own
 * INSTREAM protocol (the `clamav` service, `BLUEPRINT.md` §3.1) — a raw TCP client, not a Composer
 * package: `docs/DEPENDENCY-POLICY.md` §4 orders "standard library" above "a well-maintained
 * third-party package," and every published PHP clamd client this task found is either
 * unmaintained or wraps this exact ~20-line protocol in a much larger surface. The protocol
 * itself is small, stable, and documented in clamd's own man page — not something this class
 * invents (`docs/AGENTS.md` rule 1 is about inventing an *external* API; this is INSTREAM,
 * ClamAV's own long-stable wire format, used exactly as documented).
 *
 * Wire format (`zINSTREAM`): send the command, then the stream as a sequence of
 * `{uint32 big-endian length}{that many bytes}` chunks, terminated by a zero-length chunk; read
 * back either `stream: OK` (clean) or `stream: <signature> FOUND` (infected). Any other outcome —
 * connection refused, a timeout, a malformed or unrecognized response, `INSTREAM size limit
 * exceeded`, or any other clamd protocol error — is surfaced as
 * {@see VirusScannerUnavailableException}, never as {@see ScanOutcome::CLEAN}: `scan()` has
 * exactly one way to report "clean" and every other code path throws.
 */
final readonly class ClamdVirusScanner implements VirusScanner
{
    private const string COMMAND = "zINSTREAM\0";

    private const string OK_MARKER = 'stream: OK';

    private const string FOUND_MARKER = 'FOUND';

    public function __construct(
        private string $host,
        private int $port,
        private float $connectTimeoutSeconds,
        private float $streamTimeoutSeconds,
        private int $chunkSize,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('chunkSize must be at least 1 byte.');
        }
    }

    public function scan($stream): ScanOutcome
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->connectTimeoutSeconds);

        if ($socket === false) {
            throw VirusScannerUnavailableException::connectionFailed($this->host, $this->port);
        }

        try {
            stream_set_timeout($socket, (int) $this->streamTimeoutSeconds);

            $this->writeAll($socket, self::COMMAND);
            $this->streamChunks($socket, $stream);

            return $this->readVerdict($socket);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function writeAll($socket, string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($socket, substr($bytes, $written));
            $meta = stream_get_meta_data($socket);

            if ($meta['timed_out']) {
                throw VirusScannerUnavailableException::timedOut($this->host, $this->port);
            }

            if ($result === false || $result === 0) {
                throw VirusScannerUnavailableException::connectionFailed($this->host, $this->port);
            }

            $written += $result;
        }
    }

    /**
     * @param resource $socket
     * @param resource $documentStream
     */
    private function streamChunks($socket, $documentStream): void
    {
        $readSize = max(1, $this->chunkSize);

        while (! feof($documentStream)) {
            $chunk = fread($documentStream, $readSize);

            if ($chunk === false) {
                throw VirusScannerUnavailableException::streamReadFailed();
            }

            if ($chunk === '') {
                continue;
            }

            $this->writeAll($socket, pack('N', strlen($chunk)) . $chunk);
        }

        $this->writeAll($socket, pack('N', 0));
    }

    /** @param resource $socket */
    private function readVerdict($socket): ScanOutcome
    {
        $response = '';

        while (! feof($socket)) {
            $chunk = fread($socket, 4096);
            $meta = stream_get_meta_data($socket);

            if ($meta['timed_out']) {
                throw VirusScannerUnavailableException::timedOut($this->host, $this->port);
            }

            if ($chunk === false) {
                throw VirusScannerUnavailableException::connectionFailed($this->host, $this->port);
            }

            $response .= $chunk;

            if (str_contains($response, "\0") || str_ends_with(rtrim($response), 'FOUND') || str_contains($response, self::OK_MARKER)) {
                break;
            }
        }

        $response = trim($response, "\0\r\n ");

        if (str_contains($response, self::FOUND_MARKER)) {
            return ScanOutcome::INFECTED;
        }

        if (str_starts_with($response, self::OK_MARKER)) {
            return ScanOutcome::CLEAN;
        }

        throw VirusScannerUnavailableException::unexpectedResponse();
    }
}
