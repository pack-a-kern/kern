<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Reading;

use DateTimeImmutable;

/**
 * The minimum wage as the decisions set it, period by period — what a form's "12*3300" is checked
 * against, and what an amount in lei is counted in.
 */
final readonly class MinimumWages
{
    /** @param list<Reading> $readings the minimum wage's, oldest first */
    public function __construct(private array $readings)
    {
    }

    /** The monthly amount in lei in force on 1 January of the year, if a decision set one. */
    public function onFirstOfJanuary(int $year): ?int
    {
        $day = new DateTimeImmutable(sprintf('%04d-01-01', $year));

        foreach ($this->readings as $reading) {
            if ($reading->validFrom <= $day && ($reading->validUntil === null || $reading->validUntil >= $day)) {
                $amount = $reading->value['amount_minor'] ?? null;

                return is_int($amount) ? intdiv($amount, 100) : null;
            }
        }

        return null;
    }

    /**
     * Every monthly amount in force at some point of the year, the one of 1 January first — the wage
     * changed in the middle of 2021 (13 January), 2023, 2024 and 2026, and a form may count in either.
     *
     * @return list<int>
     */
    public function during(int $year): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $end = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        $amounts = [];

        foreach ($this->readings as $reading) {
            $amount = $reading->value['amount_minor'] ?? null;

            if (is_int($amount) && $reading->validFrom <= $end && ($reading->validUntil === null || $reading->validUntil >= $start)) {
                $amounts[] = intdiv($amount, 100);
            }
        }

        $first = $this->onFirstOfJanuary($year);

        return array_values(array_unique($first === null ? $amounts : [$first, ...$amounts]));
    }
}
