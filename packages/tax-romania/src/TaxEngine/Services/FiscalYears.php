<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Which income years a tax may be assessed for: from `TAX_FIRST_FISCAL_YEAR` to this year on Romania's
 * calendar. One rule, so the years the screen offers and the years a request may name cannot disagree —
 * the screen offers the list it is handed and keeps no rule of its own.
 */
final readonly class FiscalYears
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @return list<mixed> the validation rules for a `fiscal_year` a request names */
    public function rule(): array
    {
        return ['required', 'integer', 'min:' . $this->first(), 'max:' . $this->current()];
    }

    /**
     * The years to offer: those with activity, and this year even without any — a year in progress has
     * figures worth seeing before it has transactions — within the range, newest first.
     *
     * @param list<int> $withActivity
     * @return list<int>
     */
    public function offered(array $withActivity): array
    {
        $first = $this->first();
        $years = array_values(array_unique(array_filter(
            [$this->current(), ...$withActivity],
            static fn (int $year): bool => $year >= $first,
        )));
        rsort($years);

        return $years;
    }

    private function first(): int
    {
        $year = filter_var($this->config->get('fiscal_parameters.first_fiscal_year'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900]]);

        return is_int($year) ? $year : throw new RuntimeException('TAX_FIRST_FISCAL_YEAR must be a year, such as 2018.');
    }

    /** This year, on Romania's calendar — never the server's clock nor a browser's. */
    public function current(): int
    {
        return CarbonImmutable::now($this->config->string('fiscal_parameters.timezone'))->year;
    }
}
