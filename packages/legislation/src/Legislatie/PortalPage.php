<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

/**
 * One page of a portal search, as the portal listed it — before any of it is compared with the query.
 *
 * Unfiltered on purpose: whether the reading goes on is decided by what the portal sent, not by what
 * was kept of it. A page of ten acts of another year is a full page, and the next one is read.
 */
final readonly class PortalPage
{
    /**
     * @param  list<PortalAct>  $acts  every act the page listed, in the portal's order
     * @param  bool  $full  whether it listed a whole page's worth — a shorter page is the last
     */
    public function __construct(public array $acts, public bool $full)
    {
    }

    /** @return list<string> the acts' links, which is what tells one act from another across pages */
    public function links(): array
    {
        return array_map(static fn (PortalAct $act): string => $act->link, $this->acts);
    }
}
