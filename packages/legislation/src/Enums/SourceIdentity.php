<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/**
 * Who a request to a source says it is (ADR 0025 §3): a browser, or this installation's crawler by name
 * and contact. Which source is which is `config/sources.php` — `identify_as` on an entry, or the
 * installation's default — read by `OfficialSourceRegistry` alone.
 */
enum SourceIdentity: string
{
    case BROWSER = 'browser';
    case CRAWLER = 'crawler';
}
