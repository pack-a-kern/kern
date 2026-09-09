<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Parsing;

use DOMElement;

/**
 * Internal working structure for {@see LegislatieJustRoHtmlParser::groupOrphanContinuations()}
 * only — never returned from {@see LegislatieJustRoHtmlParser::parse()}. Deliberately mutable
 * (unlike {@see ParsedSection}): `extraDirectChildren` is appended to while a single body's
 * direct-child list is being grouped, then read once and discarded.
 */
final class OrphanContinuationGroup
{
    /** @var list<DOMElement> */
    public array $extraDirectChildren = [];

    public function __construct(
        public readonly DOMElement $primary,
    ) {
    }
}
