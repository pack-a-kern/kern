<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

/** What a whole search of the portal found, and how many times the portal was asked again on the way. */
final readonly class PortalFindings
{
    /** @param  list<PortalAct>  $acts  each once, in the portal's order */
    public function __construct(public array $acts, public int $retries)
    {
    }
}
