<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/** What an instrument's BVB page says about its issuer: the meetings on its calendar, and who holds it. */
final readonly class IssuerPage
{
    /**
     * @param list<Meeting> $meetings
     * @param list<Shareholder> $shareholders
     */
    public function __construct(
        public array $meetings,
        public array $shareholders,
        /** The day the issuer reported the structure as of, when the page says. */
        public ?DateTimeImmutable $shareholdersAsOf,
    ) {
    }
}
