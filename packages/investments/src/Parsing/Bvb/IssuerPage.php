<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

use DateTimeImmutable;

/**
 * What an instrument's BVB page says about its issuer: the meetings on its calendar, who holds it, the days
 * on its financial calendar, and its figures.
 */
final readonly class IssuerPage
{
    /**
     * @param list<Meeting> $meetings
     * @param list<Shareholder> $shareholders
     * @param list<FinancialEvent> $financialCalendar
     */
    public function __construct(
        public array $meetings,
        public array $shareholders,
        /** The day the issuer reported the structure as of, when the page says. */
        public ?DateTimeImmutable $shareholdersAsOf,
        public array $financialCalendar,
        public IssuerFigures $figures,
        /** The company logo's address exactly as the page gives it — often relative, so the caller
         * resolves it against the page URL; null when the page shows no logo. */
        public ?string $logoUrl = null,
    ) {
    }
}
