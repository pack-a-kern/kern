<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\MagicByteSnifferTest;

use App\Domain\Documents\FileSecurity\MagicByteSniffer;
use App\Domain\Documents\FileSecurity\SniffedFileType;
use Tests\Fixtures\Documents\FileFixtures;

it('identifies a PDF by its header', function (): void {
    expect((new MagicByteSniffer())->sniff(FileFixtures::minimalPdf()))->toBe(SniffedFileType::PDF);
});

it('identifies a PDF whose header follows a few leading bytes some generators prepend', function (): void {
    $withPrefix = "\x00\x01\x02" . FileFixtures::minimalPdf();

    expect((new MagicByteSniffer())->sniff($withPrefix))->toBe(SniffedFileType::PDF);
});

it('does not find a PDF signature outside the scan window', function (): void {
    $farAway = str_repeat('x', 2000) . '%PDF-1.4';

    expect((new MagicByteSniffer())->sniff($farAway))->toBeNull();
});

it('identifies a PNG by its signature', function (): void {
    expect((new MagicByteSniffer())->sniff(FileFixtures::pngWithDimensions(10, 10)))
        ->toBe(SniffedFileType::PNG);
});

it('identifies a JPEG by its signature', function (): void {
    expect((new MagicByteSniffer())->sniff(FileFixtures::jpegWithDimensions(10, 10)))->toBe(SniffedFileType::JPEG);
});

it('recognizes nothing for arbitrary bytes', function (): void {
    expect((new MagicByteSniffer())->sniff('just some plain text, not a document at all'))->toBeNull();
});

it('does not confuse a PNG signature for a PDF one', function (): void {
    expect((new MagicByteSniffer())->sniff(FileFixtures::pngWithDimensions(1, 1)))->not->toBe(SniffedFileType::PDF);
});
