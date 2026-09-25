<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Parsing;

use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use Throwable;

/**
 * Reads the one file inside a ZIP archive, with nothing but zlib.
 *
 * The PHP `zip` extension is not in this image, and adding a native extension for a single monthly
 * download is more surface than the archive needs: GLEIF ships one CSV per archive, deflated. So this
 * reads the central directory — the part of a ZIP that states the real sizes, even when the local
 * header defers them — and inflates that one entry.
 *
 * Every size is checked before memory is spent on it: the declared uncompressed size against a
 * limit, the inflate itself capped at that limit, and the result against the archive's own CRC. A
 * file claiming 1 MB that inflates to 4 GB is refused rather than unpacked.
 *
 * ZIP64 is refused rather than half-supported; a 4 GB reference list is not something this import
 * expects to meet.
 */
final class SingleFileZipReader
{
    private const string END_OF_CENTRAL_DIRECTORY = "PK\x05\x06";

    private const string CENTRAL_FILE_HEADER = "PK\x01\x02";

    private const string LOCAL_FILE_HEADER = "PK\x03\x04";

    private const int END_OF_CENTRAL_DIRECTORY_LENGTH = 22;

    private const int MAX_ARCHIVE_COMMENT_LENGTH = 0xFFFF;

    private const int LOCAL_HEADER_LENGTH = 30;

    private const int STORED = 0;

    private const int DEFLATED = 8;

    private const int ZIP64_MARKER = 0xFFFFFFFF;

    public function firstFile(string $archive, string $file, int $maxBytes): string
    {
        $directory = $this->u32($archive, $this->endOfCentralDirectory($archive, $file) + 16, $file);

        if (substr($archive, $directory, 4) !== self::CENTRAL_FILE_HEADER) {
            throw InvalidBankSourceFileException::unreadable($file, 'no central directory entry');
        }

        $method = $this->u16($archive, $directory + 10, $file);
        $crc = $this->u32($archive, $directory + 16, $file);
        $compressedSize = $this->u32($archive, $directory + 20, $file);
        $size = $this->u32($archive, $directory + 24, $file);
        $localHeader = $this->u32($archive, $directory + 42, $file);

        if (in_array(self::ZIP64_MARKER, [$compressedSize, $size, $localHeader], true)) {
            throw InvalidBankSourceFileException::unreadable($file, 'ZIP64 archives are not supported');
        }

        if ($size > $maxBytes) {
            throw InvalidBankSourceFileException::tooLarge($file, $size, $maxBytes);
        }

        if (substr($archive, $localHeader, 4) !== self::LOCAL_FILE_HEADER) {
            throw InvalidBankSourceFileException::unreadable($file, 'no local file header');
        }

        // The local header's own name and extra-field lengths, not the central directory's: the two
        // are allowed to differ, and only the local ones say where the data starts.
        $dataStart = $localHeader + self::LOCAL_HEADER_LENGTH
            + $this->u16($archive, $localHeader + 26, $file)
            + $this->u16($archive, $localHeader + 28, $file);
        $data = substr($archive, $dataStart, $compressedSize);

        if (strlen($data) !== $compressedSize) {
            throw InvalidBankSourceFileException::unreadable($file, 'the archive is truncated');
        }

        $content = match ($method) {
            self::STORED => $data,
            self::DEFLATED => $this->inflate($data, $maxBytes, $file),
            default => throw InvalidBankSourceFileException::unreadable($file, "compression method {$method} is not supported"),
        };

        if (strlen($content) !== $size || hash('crc32b', $content) !== sprintf('%08x', $crc)) {
            throw InvalidBankSourceFileException::unreadable($file, 'the unpacked file does not match its checksum');
        }

        return $content;
    }

    private function endOfCentralDirectory(string $archive, string $file): int
    {
        // Found by scanning back from the end, because an archive comment of any length may follow.
        $last = strlen($archive) - self::END_OF_CENTRAL_DIRECTORY_LENGTH;
        $first = max(0, $last - self::MAX_ARCHIVE_COMMENT_LENGTH);

        for ($offset = $last; $offset >= $first; $offset--) {
            if (substr($archive, $offset, 4) === self::END_OF_CENTRAL_DIRECTORY) {
                return $offset;
            }
        }

        throw InvalidBankSourceFileException::unreadable($file, 'not a ZIP archive');
    }

    private function inflate(string $data, int $maxBytes, string $file): string
    {
        try {
            $inflated = gzinflate($data, $maxBytes);
        } catch (Throwable) {
            $inflated = false;
        }

        if (! is_string($inflated)) {
            throw InvalidBankSourceFileException::unreadable($file, 'the compressed data is corrupt or over the size limit');
        }

        return $inflated;
    }

    private function u16(string $bytes, int $offset, string $file): int
    {
        return $this->unpack('v', $bytes, $offset, 2, $file);
    }

    private function u32(string $bytes, int $offset, string $file): int
    {
        return $this->unpack('V', $bytes, $offset, 4, $file);
    }

    private function unpack(string $format, string $bytes, int $offset, int $length, string $file): int
    {
        $slice = $offset >= 0 ? substr($bytes, $offset, $length) : '';
        $value = strlen($slice) === $length ? unpack($format, $slice) : false;

        if (! is_array($value) || ! is_int($value[1] ?? null)) {
            throw InvalidBankSourceFileException::unreadable($file, 'the archive is truncated');
        }

        return $value[1];
    }
}
