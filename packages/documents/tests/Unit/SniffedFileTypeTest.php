<?php

declare(strict_types=1);

namespace Kern\Documents\Tests\Unit\SniffedFileTypeTest;

use Kern\Documents\FileSecurity\SniffedFileType;
use Kern\Documents\Testing\FileFixtures;

it('identifies a PDF by its header', function (): void {
    expect(SniffedFileType::sniff(FileFixtures::minimalPdf()))->toBe(SniffedFileType::PDF);
});

it('identifies a PDF whose header follows a few leading bytes some generators prepend', function (): void {
    $withPrefix = "\x00\x01\x02" . FileFixtures::minimalPdf();

    expect(SniffedFileType::sniff($withPrefix))->toBe(SniffedFileType::PDF);
});

it('does not find a PDF signature outside the scan window', function (): void {
    $farAway = str_repeat('x', 2000) . '%PDF-1.4';

    expect(SniffedFileType::sniff($farAway))->toBeNull();
});

it('identifies a PNG by its signature', function (): void {
    expect(SniffedFileType::sniff(FileFixtures::pngWithDimensions(10, 10)))
        ->toBe(SniffedFileType::PNG);
});

it('identifies a JPEG by its signature', function (): void {
    expect(SniffedFileType::sniff(FileFixtures::jpegWithDimensions(10, 10)))->toBe(SniffedFileType::JPEG);
});

it('recognizes nothing for arbitrary bytes', function (): void {
    expect(SniffedFileType::sniff('just some plain text, not a document at all'))->toBeNull();
});

it('does not confuse a PNG signature for a PDF one', function (): void {
    expect(SniffedFileType::sniff(FileFixtures::pngWithDimensions(1, 1)))->not->toBe(SniffedFileType::PDF);
});

it('reads a delimited spreadsheet as CSV, whichever delimiter a locale writes', function (string $csv): void {
    expect(SniffedFileType::sniff($csv))->toBe(SniffedFileType::CSV);
})->with([
    'comma' => ["Data,Suma,Detalii\n2026-01-05,123.45,Chirie\n"],
    // What a Romanian locale's Excel writes, because the comma is the decimal separator there.
    'semicolon' => ["Data;Suma;Detalii\n2026-01-05;123,45;Chirie\n"],
    'tab' => ["Data\tSuma\tDetalii\n2026-01-05\t123.45\tChirie\n"],
    // Excel prefixes a BOM on UTF-8 CSV; it says nothing about the delimiter.
    'utf-8 BOM' => ["\u{FEFF}Data,Suma,Detalii\n2026-01-05,123.45,Chirie\n"],
    // Windows line endings, which is what an export from a bank actually arrives with.
    'CRLF' => ["Data,Suma,Detalii\r\n2026-01-05,123.45,Chirie\r\n"],
    // The case the quote-aware count exists for: a comma inside a counterparty name must not be
    // mistaken for a column separator, or the two rows disagree and the file is unrecognisable.
    'quoted delimiter' => ["Data,Contrapartida,Suma\n2026-01-05,\"Popescu, Ion\",123.45\n"],
]);

it('refuses prose, even prose with commas in it, rather than calling someone\'s notes a spreadsheet', function (): void {
    expect(SniffedFileType::sniff("Draga Ion,\n\nIti scriu despre factura de luna trecuta, care nu a fost platita.\n"))
        ->toBeNull();
});

it('refuses a single line, however delimited', function (): void {
    // One row is not a table, and nothing this pipeline reads arrives as one.
    expect(SniffedFileType::sniff('Data,Suma,Detalii'))->toBeNull();
});

it('refuses a binary file whose first bytes happen to look delimited', function (): void {
    // A NUL in the first kilobyte is the tell: every binary container has one, text never does.
    expect(SniffedFileType::sniff("a,b,c\nd,e,f\n\x00\x01\x02binary"))->toBeNull();
});

it('prefers a real signature over the structural CSV test', function (): void {
    // A PDF whose leading bytes contain commas is a PDF. Signature types are tried first, which is
    // what makes the order in `cases()` load-bearing.
    expect(SniffedFileType::sniff("%PDF-1.7\n1,2,3\n4,5,6\n"))->toBe(SniffedFileType::PDF);
});

it('offers every accepted type to the picker, as both MIME type and extension', function (): void {
    $accept = SniffedFileType::acceptAttribute();

    foreach (SniffedFileType::cases() as $type) {
        expect($accept)->toContain($type->value);

        foreach ($type->allowedExtensions() as $extension) {
            expect($accept)->toContain('.' . $extension);
        }
    }
});
