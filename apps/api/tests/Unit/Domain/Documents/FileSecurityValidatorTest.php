<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\FileSecurityValidatorTest;

use App\Domain\Documents\FileSecurity\Exceptions\ExtensionMismatchException;
use App\Domain\Documents\FileSecurity\Exceptions\FileTooLargeException;
use App\Domain\Documents\FileSecurity\Exceptions\ImagePixelLimitExceededException;
use App\Domain\Documents\FileSecurity\Exceptions\UnsupportedFileTypeException;
use App\Domain\Documents\FileSecurity\FilenameSanitizer;
use App\Domain\Documents\FileSecurity\FileSecurityValidator;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use RuntimeException;
use Tests\Fixtures\Documents\FileFixtures;

/**
 * `BLUEPRINT.md` §14.2 file security — exercised against real temp files (the same shape Laravel's
 * own upload handling hands the validator), never against in-memory strings, so `filesize()`/
 * `hash_file()`/`getimagesize()` are exercised exactly as production does.
 */
function makeValidator(int $maxPdfBytes = 1_000_000, int $maxImageBytes = 1_000_000, int $maxTextBytes = 1_000_000, int $maxImagePixels = 1_000_000): FileSecurityValidator
{
    return new FileSecurityValidator(new FilenameSanitizer(), $maxPdfBytes, $maxImageBytes, $maxTextBytes, $maxImagePixels);
}

/** @return string the temp file path; the OS cleans it up, same as PHP's own upload tmp files. */
function tempFileWith(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'doctest');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temp file for the test.');
    }

    file_put_contents($path, $content);

    return $path;
}

it('accepts a valid PDF and reports its real sniffed type, sanitized name, size, and hash', function (): void {
    $content = FileFixtures::minimalPdf();
    $result = makeValidator()->validate(tempFileWith($content), 'invoice.pdf');

    expect($result->sniffedType)->toBe(SniffedFileType::PDF)
        ->and($result->sanitizedFilename)->toBe('invoice.pdf')
        ->and($result->sizeBytes)->toBe(strlen($content))
        ->and($result->sha256)->toBe(hash('sha256', $content));
});

it('accepts a valid PNG within the pixel limit', function (): void {
    $result = makeValidator(maxImagePixels: 10_000)->validate(
        tempFileWith(FileFixtures::pngWithDimensions(50, 50)),
        'receipt.png',
    );

    expect($result->sniffedType)->toBe(SniffedFileType::PNG);
});

it('rejects a file whose bytes do not match any accepted type', function (): void {
    $path = tempFileWith('this is not a document, just plain text');

    expect(fn () => makeValidator()->validate($path, 'notes.pdf'))->toThrow(UnsupportedFileTypeException::class);
});

it('ignores the client-supplied MIME entirely — only the declared extension is cross-checked, and only against real content', function (): void {
    // A PNG's actual bytes, dishonestly named ".pdf" — BLUEPRINT.md §14.2 "ignore supplied MIME".
    $path = tempFileWith(FileFixtures::pngWithDimensions(10, 10));

    expect(fn () => makeValidator()->validate($path, 'totally-a-pdf.pdf'))
        ->toThrow(ExtensionMismatchException::class);
});

it('refuses a file whose magic bytes contradict its declared extension', function (): void {
    $path = tempFileWith(FileFixtures::jpegWithDimensions(10, 10));

    try {
        makeValidator()->validate($path, 'statement.pdf');
        $this->fail('Expected ExtensionMismatchException to be thrown.');
    } catch (ExtensionMismatchException $exception) {
        expect($exception->reason()->value)->toBe('EXTENSION_MISMATCH');
    }
});

it('accepts any allowed extension alias for a sniffed type (jpg and jpeg both match JPEG)', function (): void {
    $path = tempFileWith(FileFixtures::jpegWithDimensions(10, 10));

    expect(makeValidator()->validate($path, 'photo.jpg')->sniffedType)->toBe(SniffedFileType::JPEG);
    expect(makeValidator()->validate($path, 'photo.jpeg')->sniffedType)->toBe(SniffedFileType::JPEG);
});

it('rejects a PDF larger than the configured limit', function (): void {
    $path = tempFileWith(FileFixtures::minimalPdf() . str_repeat('x', 2000));

    expect(fn () => makeValidator(maxPdfBytes: 100)->validate($path, 'big.pdf'))
        ->toThrow(FileTooLargeException::class);
});

it('applies the image size limit, independently of the PDF limit', function (): void {
    $path = tempFileWith(FileFixtures::pngWithDimensions(10, 10) . str_repeat('x', 2000));

    expect(fn () => makeValidator(maxPdfBytes: 1_000_000, maxImageBytes: 100)->validate($path, 'big.png'))
        ->toThrow(FileTooLargeException::class);
});

it('rejects an image exceeding the configured pixel-count ceiling', function (): void {
    $path = tempFileWith(FileFixtures::pngWithDimensions(1000, 1000)); // 1,000,000 px

    expect(fn () => makeValidator(maxImagePixels: 10_000)->validate($path, 'huge.png'))
        ->toThrow(ImagePixelLimitExceededException::class);
});

it('never applies the pixel-count check to a PDF', function (): void {
    // Same nominal "size" concept does not apply to PDFs — this only proves the branch is skipped
    // (a valid small PDF is accepted) even with a pixel limit of zero.
    $result = makeValidator(maxImagePixels: 0)->validate(tempFileWith(FileFixtures::minimalPdf()), 'doc.pdf');

    expect($result->sniffedType)->toBe(SniffedFileType::PDF);
});

it('sanitizes a dangerous filename while still validating real content', function (): void {
    $path = tempFileWith(FileFixtures::minimalPdf());

    $result = makeValidator()->validate($path, '../../etc/passwd.pdf');

    expect($result->sanitizedFilename)->toBe('passwd.pdf');
});

it('accepts a spreadsheet and holds it to the text size limit', function (): void {
    $csv = "Data,Suma,Detalii\n2026-01-05,123.45,Chirie\n";
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $csv);

    expect(makeValidator()->validate($path, 'extras.csv')->sniffedType)->toBe(SniffedFileType::CSV);

    // Its own ceiling, not the PDF one: a text file that large is not a statement anybody exported.
    expect(fn () => makeValidator(maxTextBytes: 10)->validate($path, 'extras.csv'))
        ->toThrow(FileTooLargeException::class);
});

it('refuses a spreadsheet whose declared extension disagrees with its bytes', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, "Data,Suma\n2026-01-05,1.00\n");

    expect(fn () => makeValidator()->validate($path, 'extras.pdf'))
        ->toThrow(ExtensionMismatchException::class);
});
