<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

use Kern\Investments\Enums\Programme;
use Kern\Investments\Parsing\Html\HtmlPage;

/** The Ministry of Finance's page of subscription lists, and how each list's link is told on it. */
final readonly class SubscriptionLists
{
    public const string SOURCE = 'MF';

    /** @param array<string, string> $filePatterns by programme */
    public function __construct(
        public string $pageUrl,
        private array $filePatterns,
    ) {
    }

    /**
     * The address of this programme's list: the first link on the page that matches its pattern, made whole
     * when the page writes it from its own root (`/static/…`). None, null — and the fetcher still checks the
     * address is on the source's allowlist.
     */
    public function linkIn(string $page, Programme $programme): ?string
    {
        foreach (HtmlPage::load($page)->querySelectorAll('a[href]') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));

            if (preg_match($this->filePatterns[$programme->value], $href) !== 1) {
                continue;
            }

            if (str_starts_with($href, 'https://')) {
                return $href;
            }

            if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
                return 'https://' . parse_url($this->pageUrl, PHP_URL_HOST) . $href;
            }
        }

        return null;
    }
}
