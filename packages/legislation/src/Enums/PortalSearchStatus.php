<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/** `ext_portal_searches.status` — where a queued search of the portal is (ADR 0024). */
enum PortalSearchStatus: string
{
    /** Pages are still being read. */
    case RUNNING = 'running';

    /** Every page was read: the acts are all the portal listed. */
    case DONE = 'done';

    /** A page after the first would not answer however often it was asked: the acts are the pages before it. */
    case INCOMPLETE = 'incomplete';

    /** The first page would not answer: there are no acts to show. */
    case FAILED = 'failed';

    /** Stopped by whoever started it, or by their starting another. */
    case CANCELLED = 'cancelled';

    public function isFinished(): bool
    {
        return $this !== self::RUNNING;
    }
}
