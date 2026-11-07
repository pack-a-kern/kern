<?php

declare(strict_types=1);

namespace Kern\Legislation\Fetching;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Exceptions\SourceDisabledException;
use Kern\Legislation\Fetching\Exceptions\FetchSizeExceededException;
use Kern\Legislation\Fetching\Exceptions\HostNotAllowedException;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\Legislation\Legislatie\Exceptions\PortalBrowserException;
use Kern\Platform\BrowserWorker\BrowserWorkerClient;
use Kern\Platform\BrowserWorker\Exceptions\BrowserPageRefusedException;
use RuntimeException;

/**
 * A page of an official source, opened by the browser worker — for a site that answers a browser and
 * refuses a plain client.
 *
 * The same page a download would have stored: the worker hands back the bytes the server sent for the
 * page it opened, not Chromium's rendering of them, so the raw store and the parser receive what they
 * always did. The same checks as a download, too ({@see PageFetcher}): the address against the
 * source's allowlist before the worker is asked, and the address it was finally served from after,
 * the ceiling on the page, and a refused page reported with its status.
 *
 * One page per call, in a browser opened for it and closed after: nothing is kept between pages, so a
 * law of ninety consolidations is ninety short runs with the memory of one.
 */
final readonly class BrowserPageFetcher implements PageFetcher
{
    /** The playbook's sequence that opens `{{ secret.url }}` and keeps it. */
    private const string SEQUENCE = 'page';

    public function __construct(
        private BrowserWorkerClient $worker,
        private OfficialSourceRegistry $registry,
        private ConfigRepository $config,
    ) {
    }

    public function fetch(string $sourceCode, string $url): FetchedDocument
    {
        $definition = $this->registry->find($sourceCode);

        if (! $definition->enabled) {
            throw SourceDisabledException::forCode($sourceCode);
        }

        if (! $definition->allowsUrl($url)) {
            throw HostNotAllowedException::forUrl($url);
        }

        $playbook = $definition->playbook === null ? null : $this->config->get($definition->playbook . '.playbook');

        if (! is_array($playbook) || $playbook === []) {
            throw new RuntimeException(sprintf('Source "%s" has no browser playbook to read its pages with.', $sourceCode));
        }

        $maxBytes = $this->config->integer('sources.fetch.max_response_bytes');

        /** @var array<string, mixed> $playbook */
        $page = PortalBrowserException::translate(function () use ($playbook, $url, $maxBytes) {
            try {
                return $this->worker->page($playbook, self::SEQUENCE, ['url' => $url], $maxBytes);
            } catch (BrowserPageRefusedException $exception) {
                throw $exception->pageStatus !== null
                    ? UnexpectedStatusException::forUrl($url, $exception->pageStatus)
                    : FetchSizeExceededException::forUrl($url, $maxBytes);
            }
        });

        // Where it was served from, after the redirects the worker followed and checked against the
        // playbook's own list — checked again against the source's, which is the one this package keeps.
        if (! $definition->allowsUrl($page->url)) {
            throw HostNotAllowedException::forUrl($page->url);
        }

        return new FetchedDocument(
            body: $page->body,
            contentType: $page->contentType,
            finalUrl: $page->url,
            statusCode: 200,
        );
    }
}
