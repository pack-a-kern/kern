<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

use Kern\Legislation\Enums\UserAgentBrowser;
use Kern\Legislation\Enums\UserAgentSystem;
use Kern\Platform\BrowserWorker\BrowserEngine;

/** A `User-Agent` from the list, with what it names. */
final readonly class KnownUserAgent
{
    public function __construct(
        public string $userAgent,
        public BrowserEngine $engine,
        public UserAgentSystem $system,
        public UserAgentBrowser $browser,
        public int $version,
    ) {
    }
}
