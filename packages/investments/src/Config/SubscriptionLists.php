<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

use Kern\Investments\Enums\Programme;

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

    /** The first link on the page to this programme's list, as the page writes it; none, null. */
    public function linkIn(string $page, Programme $programme): ?string
    {
        return preg_match($this->filePatterns[$programme->value], $page, $link) === 1 ? $link[0] : null;
    }
}
