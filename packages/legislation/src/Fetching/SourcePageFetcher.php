<?php

declare(strict_types=1);

namespace Kern\Legislation\Fetching;

use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\DataTransferObjects\SourceDefinition;

/**
 * Each source's pages, the way its settings say: downloaded, or opened in the browser worker.
 *
 * Decided per page, from the configuration as it stands, so a change on the settings screen reaches the
 * next page a running import reads. Never one way in place of the other when it fails: a page the
 * browser could not read is reported, not downloaded instead — a fallback would hide which way works.
 */
final readonly class SourcePageFetcher implements PageFetcher
{
    public function __construct(
        private AllowlistedHttpFetcher $direct,
        private BrowserPageFetcher $browser,
        private OfficialSourceRegistry $registry,
    ) {
    }

    public function fetch(string $sourceCode, string $url): FetchedDocument
    {
        return $this->registry->find($sourceCode)->pages === SourceDefinition::PAGES_BROWSER
            ? $this->browser->fetch($sourceCode, $url)
            : $this->direct->fetch($sourceCode, $url);
    }
}
