<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Parsing;

use DateTimeImmutable;
use DateTimeZone;
use Kern\TaxRomania\Banks\DataTransferObjects\DownloadLink;
use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;

/**
 * Finds the newest file on each publisher's download page.
 *
 * The configured address is always the page, never the file: every one of these files carries its
 * date in its name and is replaced by the next, so a file address would be out of date the day after
 * it was written down. What is fixed is how each publisher names its files, and that is what these
 * patterns describe — the same kind of knowledge `BnrXmlParser` has about BNR's XML.
 *
 * Where a page lists several, the newest date wins rather than the first link, so a page that
 * reorders its list still yields the latest file.
 */
final class SourceDownloadLinks
{
    public function ecbInstitutionList(string $html, string $pageUrl): DownloadLink
    {
        // `mfi_csv_260914.csv.gz`, but not `mfi_csv_update_260914.csv.gz`, which is only that day's changes.
        return $this->newest($html, $pageUrl, 'ECB institution list', static function (string $href): ?array {
            return preg_match('~/mfi_csv_(\d{6})\.csv\.gz(?:\?.*)?$~', $href, $match) === 1 ? [$match[1], '!ymd'] : null;
        });
    }

    public function gleifBicLeiMapping(string $html, string $pageUrl): DownloadLink
    {
        // The link itself is an opaque id; its date is in the link text, `LEI-BIC-20260828.zip`.
        return $this->newest($html, $pageUrl, 'GLEIF BIC-to-LEI file', static function (string $href, string $text): ?array {
            return preg_match('~/api/v2/bic-lei/[0-9a-f-]+/download$~i', $href) === 1
                && preg_match('~LEI-BIC-(\d{8})\.zip~', $text, $match) === 1
                ? [$match[1], '!Ymd']
                : null;
        });
    }

    public function gleifLegalForms(string $html, string $pageUrl): DownloadLink
    {
        // `2026-02-19-elf-code-list-v1.6.csv`
        return $this->newest($html, $pageUrl, 'ISO 20275 legal forms list', static function (string $href): ?array {
            return preg_match('~/(\d{4}-\d{2}-\d{2})-elf-code-list[^/]*\.csv$~', $href, $match) === 1 ? [$match[1], '!Y-m-d'] : null;
        });
    }

    /**
     * @param  callable(string, string): (array{0: string, 1: string}|null)  $match  the date and its format, or null for a link that is not the file
     */
    private function newest(string $html, string $pageUrl, string $file, callable $match): DownloadLink
    {
        $newest = null;

        foreach ($this->anchors($html) as [$href, $text]) {
            $found = $match($href, $text);
            $date = $found === null ? false : DateTimeImmutable::createFromFormat($found[1], $found[0], new DateTimeZone('UTC'));
            $url = $this->absolute($href, $pageUrl);

            if ($date === false || $url === null) {
                continue;
            }

            if ($newest === null || $date > $newest->publishedOn) {
                $newest = new DownloadLink($url, $date);
            }
        }

        return $newest ?? throw InvalidBankSourceFileException::noDownloadLink($file);
    }

    /**
     * @return list<array{0: string, 1: string}> each link's href and visible text
     */
    private function anchors(string $html): array
    {
        preg_match_all('~<a\b[^>]*\bhref\s*=\s*"([^"]+)"[^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $anchor): array => [
                html_entity_decode($anchor[1], ENT_QUOTES | ENT_HTML5),
                trim(html_entity_decode(strip_tags($anchor[2]), ENT_QUOTES | ENT_HTML5)),
            ],
            $matches,
        );
    }

    /**
     * A page-relative link made absolute against the page. Only `https` and root-relative links are
     * followed; anything else is ignored here, and the fetcher would refuse it anyway.
     */
    private function absolute(string $href, string $pageUrl): ?string
    {
        if (str_starts_with(strtolower($href), 'https://')) {
            return $href;
        }

        $host = parse_url($pageUrl, PHP_URL_HOST);

        return str_starts_with($href, '/') && ! str_starts_with($href, '//') && is_string($host)
            ? "https://{$host}{$href}"
            : null;
    }
}
