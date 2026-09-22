<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

/** What one structure file says: its values, what could not be made a value, and the wages it counts in. */
final readonly class FormValues
{
    /**
     * @param  list<Reading>  $readings
     * @param  list<array<string, mixed>>  $unmatched
     * @param  list<array{year: int, wage: int, url: string, form_year: int}>  $wagesSeen
     */
    public function __construct(
        public array $readings,
        public array $unmatched,
        public array $wagesSeen,
    ) {
    }
}
