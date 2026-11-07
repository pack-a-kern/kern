<?php

declare(strict_types=1);

namespace Tests\Unit\TaxRomania\Banks\DownloadLinkTest;

use DateTimeImmutable;
use Kern\TaxRomania\Banks\DataTransferObjects\DownloadLink;

/**
 * A publisher's file under the path its pages are served from. GLEIF's legal-forms page lives at /en/…
 * and linked its CSV as /lei-data/…, which redirected to a copy no longer there (seen 2026-11-07),
 * while the same path under /en/ answered the file.
 */
function link(string $url): DownloadLink
{
    return new DownloadLink($url, new DateTimeImmutable('2026-02-19'));
}

it('puts a link on the publisher’s own host under the path its pages use', function (): void {
    expect(link('https://www.gleif.org/lei-data/code-lists/x/2026-02-19-elf-code-list-v1.6.csv')->underPath('www.gleif.org', '/en')->url)
        ->toBe('https://www.gleif.org/en/lei-data/code-lists/x/2026-02-19-elf-code-list-v1.6.csv');
});

it('leaves a link already under that path, or on another host, as written', function (string $url): void {
    expect(link($url)->underPath('www.gleif.org', '/en')->url)->toBe($url);
})->with([
    'already under it' => ['https://www.gleif.org/en/lei-data/file.csv'],
    'another host' => ['https://mapping.gleif.org/api/v2/bic-lei/fc357d54/download'],
]);

it('reads "under the path" as a whole segment, not the letters it starts with', function (): void {
    expect(link('https://www.gleif.org/enrol/file.csv')->underPath('www.gleif.org', '/en')->url)->toBe('https://www.gleif.org/en/enrol/file.csv');
});

it('keeps the query and the file’s date', function (): void {
    $moved = link('https://www.gleif.org/files/list.csv?v=2')->underPath('WWW.GLEIF.ORG', 'en/');

    expect([$moved->url, $moved->publishedOn->format('Y-m-d')])->toBe(['https://www.gleif.org/en/files/list.csv?v=2', '2026-02-19']);
});
