<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

/** A page as its server sent it, kept by a `source` step: the bytes, their type, and where they came from. */
final readonly class BrowserPage
{
    public function __construct(
        public string $body,
        public ?string $contentType,
        public string $url,
    ) {
    }
}
