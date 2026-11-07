<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

/**
 * One way of asking legislatie.just.ro which acts it has: its SOAP service ({@see SoapPortalSearch})
 * or its search page in the browser worker ({@see BrowserPortalSearch}).
 *
 * Each answers with the acts the portal listed, as {@see PortalAct}s built the same way, and nothing
 * more: {@see PortalSearch} keeps the ones that match, so what a person is shown and what an import
 * finds cannot depend on which way was asked.
 */
interface PortalSearchMethod
{
    /** @return list<PortalAct> every act the portal lists for this number and year */
    public function byNumber(int $number, int $year): array;

    /**
     * @param  int|null  $year  a hint the method may pass on to narrow what the portal sends
     * @return list<PortalAct> every act the portal lists whose title holds these words
     */
    public function byTitle(string $words, ?int $year): array;
}
