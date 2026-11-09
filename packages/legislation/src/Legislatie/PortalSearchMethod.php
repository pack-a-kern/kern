<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

/**
 * One way of asking legislatie.just.ro which acts it has: its SOAP service ({@see SoapPortalSearch})
 * or its search page in the browser worker ({@see BrowserPortalSearch}).
 *
 * A page at a time, as the portal listed it, with {@see PortalAct}s built the same way either way:
 * {@see PortalSearch} decides which to keep and when to stop, so what a person is shown and what an
 * import finds cannot depend on which way was asked.
 *
 * A page the portal keeps refusing after the method's own retries is thrown — the site's error page as
 * {@see Exceptions\PortalSearchRefusedException}, the service's error as the fetcher's — never answered
 * as an empty page, which would read as the end of the results.
 */
interface PortalSearchMethod
{
    /**
     * @param  int  $page  counted from 1
     */
    public function page(PortalQuery $query, int $page): PortalPage;
}
