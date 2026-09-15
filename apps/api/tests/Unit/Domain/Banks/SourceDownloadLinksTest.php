<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banks\SourceDownloadLinksTest;

use App\Domain\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use App\Domain\Banks\Parsing\SourceDownloadLinks;

it('takes the newest ECB list, made absolute, and never the day’s change file', function (): void {
    $html = <<<'HTML'
        <a href="/stats/dla/mfi_MID/mfi_csv_260913.csv.gz">mfi_csv_260913.csv.gz</a>
        <a href="/stats/dla/mfi_MID/mfi_csv_update_260915.csv.gz">update</a>
        <a href="/stats/dla/mfi_MID/mfi_csv_260914.csv.gz">mfi_csv_260914.csv.gz</a>
        HTML;

    $link = (new SourceDownloadLinks())->ecbInstitutionList($html, 'https://www.ecb.europa.eu/stats/list.html');

    expect($link->url)->toBe('https://www.ecb.europa.eu/stats/dla/mfi_MID/mfi_csv_260914.csv.gz')
        ->and($link->publishedOn->format('Y-m-d'))->toBe('2026-09-14');
});

it('dates a GLEIF archive by its link text, since the link itself is an opaque id', function (): void {
    $html = <<<'HTML'
        <a href="https://mapping.gleif.org/api/v2/bic-lei/f00181e8-6c75-46e2-a69a-65d935fb97ee/download">LEI-BIC-20260724.zip</a>
        <a href="https://mapping.gleif.org/api/v2/bic-lei/fc357d54-5c42-4a16-95ae-7aab8b148c4d/download">LEI-BIC-20260828.zip</a>
        HTML;

    $link = (new SourceDownloadLinks())->gleifBicLeiMapping($html, 'https://www.gleif.org/en/page');

    expect($link->url)->toBe('https://mapping.gleif.org/api/v2/bic-lei/fc357d54-5c42-4a16-95ae-7aab8b148c4d/download')
        ->and($link->publishedOn->format('Y-m-d'))->toBe('2026-08-28');
});

it('finds the legal forms list by the date in its file name', function (): void {
    $html = '<a href="https://www.gleif.org/lei-data/code-lists/2026-02-19-elf-code-list-v1.6.csv">CSV</a>';

    expect((new SourceDownloadLinks())->gleifLegalForms($html, 'https://www.gleif.org/en/page')->publishedOn->format('Y-m-d'))
        ->toBe('2026-02-19');
});

it('stops the import when a page no longer links the file', function (): void {
    (new SourceDownloadLinks())->ecbInstitutionList('<a href="/somewhere-else.html">moved</a>', 'https://www.ecb.europa.eu/');
})->throws(InvalidBankSourceFileException::class, 'No download link');
