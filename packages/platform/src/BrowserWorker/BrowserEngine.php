<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

/**
 * The browsers the worker drives, each in its image (`apps/browser-worker`, `context.engine`).
 *
 * A `User-Agent` is honest only on its own engine: a page reads the engine from more than the header.
 */
enum BrowserEngine: string
{
    case CHROMIUM = 'chromium';
    case FIREFOX = 'firefox';
    case WEBKIT = 'webkit';
}
