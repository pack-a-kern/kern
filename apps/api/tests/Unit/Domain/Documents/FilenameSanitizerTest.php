<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\FilenameSanitizerTest;

use App\Domain\Documents\FileSecurity\FilenameSanitizer;

it('keeps an already-safe filename unchanged', function (): void {
    expect((new FilenameSanitizer())->sanitize('invoice-2026.pdf'))->toBe('invoice-2026.pdf');
});

it('strips a directory traversal attempt down to the basename', function (): void {
    expect((new FilenameSanitizer())->sanitize('../../etc/passwd'))->toBe('passwd');
});

it('strips a Windows-style path down to the basename', function (): void {
    expect((new FilenameSanitizer())->sanitize('C:\\Users\\ion\\Documents\\statement.pdf'))->toBe('statement.pdf');
});

it('strips control characters, including an embedded NUL byte', function (): void {
    expect((new FilenameSanitizer())->sanitize("evil\x00.pdf.exe"))->toBe('evil.pdf.exe');
});

it('strips shell-metacharacters and other unsafe punctuation', function (): void {
    expect((new FilenameSanitizer())->sanitize('inv$(rm -rf ~).pdf'))->toBe('invrm -rf .pdf');
});

it('falls back to a fixed name when nothing safe remains', function (): void {
    expect((new FilenameSanitizer())->sanitize('$(){}[]'))->toBe('document');
});

it('preserves diacritics and other unicode letters', function (): void {
    expect((new FilenameSanitizer())->sanitize('contract-închiriere.pdf'))->toBe('contract-închiriere.pdf');
});

it('truncates an excessively long filename', function (): void {
    $long = str_repeat('a', 500) . '.pdf';

    expect(mb_strlen((new FilenameSanitizer())->sanitize($long)))->toBeLessThanOrEqual(200);
});
