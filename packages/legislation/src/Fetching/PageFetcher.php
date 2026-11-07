<?php

declare(strict_types=1);

namespace Kern\Legislation\Fetching;

use Kern\Legislation\Fetching\Exceptions\FetchSizeExceededException;
use Kern\Legislation\Fetching\Exceptions\HostNotAllowedException;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;

/**
 * One page of an official source, as its server sent it: the bytes, their type, where they came from.
 *
 * However it is read — downloaded ({@see AllowlistedHttpFetcher}) or opened in the browser worker
 * ({@see BrowserPageFetcher}) — the same promises hold: only a host the source's allowlist names, at
 * no point along the way; a page past the size ceiling refused whole; a page its server refused
 * reported with its status rather than kept. So what is stored and parsed afterwards cannot tell, and
 * need not care, which way it came.
 */
interface PageFetcher
{
    /**
     * @throws HostNotAllowedException
     * @throws FetchSizeExceededException
     * @throws UnexpectedStatusException
     */
    public function fetch(string $sourceCode, string $url): FetchedDocument;
}
