<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Tests\Unit\Banks\SingleFileZipReaderTest;

use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use Kern\TaxRomania\Banks\Parsing\SingleFileZipReader;

/**
 * A one-entry ZIP built byte by byte, as GLEIF's archives are laid out: a local header, the deflated
 * data, a central directory entry, and the end-of-directory record. Built here rather than read from a
 * fixture file so each field the reader relies on is visible in the test.
 */
function zipOf(string $name, string $content, ?int $declaredSize = null): string
{
    $data = (string) gzdeflate($content);
    $crc = (int) hexdec(hash('crc32b', $content));
    $size = $declaredSize ?? strlen($content);

    $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 8, 0, 0, $crc, strlen($data), $size, strlen($name), 0) . $name . $data;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 8, 0, 0, $crc, strlen($data), $size, strlen($name), 0, 0, 0, 0, 0, 0) . $name;

    return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
}

it('reads the one file inside the archive', function (): void {
    $csv = "LEI,BIC\n549300RG3H390KEL8896,BTRLRO22XXX\n";

    expect((new SingleFileZipReader())->firstFile(zipOf('lei-bic.csv', $csv), 'test file', 1024))->toBe($csv);
});

it('refuses a file that declares more than the limit, before unpacking it', function (): void {
    (new SingleFileZipReader())->firstFile(zipOf('big.csv', str_repeat('a', 2048)), 'test file', 1024);
})->throws(InvalidBankSourceFileException::class, 'above the 1024-byte limit');

it('refuses a file whose declared size does not match what it unpacks to', function (): void {
    (new SingleFileZipReader())->firstFile(zipOf('lies.csv', 'LEI,BIC', 3), 'test file', 1024);
})->throws(InvalidBankSourceFileException::class, 'checksum');

it('refuses something that is not a ZIP at all', function (): void {
    (new SingleFileZipReader())->firstFile('<html>Not found</html>', 'test file', 1024);
})->throws(InvalidBankSourceFileException::class, 'not a ZIP archive');
