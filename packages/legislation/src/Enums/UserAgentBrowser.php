<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/** The browser a `User-Agent` names. Its engine is the system's to say as well: every iOS browser is WebKit. */
enum UserAgentBrowser: string
{
    case CHROME = 'chrome';
    case EDGE = 'edge';
    case OPERA = 'opera';
    case FIREFOX = 'firefox';
    case SAFARI = 'safari';
}
