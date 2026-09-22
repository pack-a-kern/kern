<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use App\Domain\TaxKnowledge\FiscalParameters\Forms\FormText;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCheck;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterDefinition;
use DateTimeImmutable;

/**
 * The values one D212 structure file states, each for the year it is about.
 *
 * A formula captures what `config/fiscal_parameters.php` names: a `percent` ("cass_baza *10/100"), or
 * a number of minimum `wages` with the `wage` it counts them in ("cass_baza = 6*3300"), or an
 * `amount` in lei that is so many wages ("12 salarii minime … (39.600 lei)", "cass_baza <=22800").
 *
 * The year: a declaration reports last year's income and estimates this year's, so each formula
 * lists the years it can be about. Where it names a wage, the year whose minimum wage on 1 January
 * that is decides — "12*3000" in the 2024 file is 2023's; failing that, a year it was in force in at
 * all (2 300 lei from 13 January 2021), noted on the value; when none is, the first year stands and
 * the disagreement is kept. An amount that is not a whole number of any of that year's wages is
 * never made a value: the 2021 file's cap of 27 360 lei is 12.27 wages of 2 230.
 */
final readonly class FormValueReader
{
    public function __construct(private ParameterCatalog $catalog)
    {
    }

    public function read(FormText $form, MinimumWages $wages): FormValues
    {
        $readings = [];
        $unmatched = [];
        $wagesSeen = [];

        if ($form->text === null) {
            return new FormValues([], [], []);
        }

        foreach ($this->catalog->all() as $definition) {
            /** @var array<int, list<array{number: int, quote: string, checks: list<array<string, mixed>>}>> $byYear */
            $byYear = [];

            foreach ($definition->formPatterns as $formula) {
                preg_match_all($formula['pattern'], $form->text, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $observed = $this->observe($definition, $formula['years'], $match, $form->structure->form_year, $wages);

                    if (isset($observed['unmatched'])) {
                        $unmatched[] = ['parameter' => $definition->code, 'quote' => self::quote($match[0]), ...$observed['unmatched']];

                        continue;
                    }

                    $byYear[$observed['year']][] = ['number' => $observed['number'], 'quote' => self::quote($match[0]), 'checks' => $observed['checks']];

                    if ($observed['wage'] !== null) {
                        $wagesSeen[] = ['year' => $observed['year'], 'wage' => $observed['wage'], 'url' => $form->structure->url];
                    }
                }
            }

            foreach ($byYear as $year => $observations) {
                array_push($readings, ...$this->readingsFor($definition, $year, $observations, $form));
            }
        }

        return new FormValues($readings, $unmatched, $wagesSeen);
    }

    /**
     * One formula's match, as a number for a year — or why it cannot be one.
     *
     * @param  list<int>  $years  offsets from the file's year, in order of preference
     * @param  array<int|string, string>  $match
     * @return array{year: int, number: int, wage: int|null, checks: list<array<string, mixed>>}|array{unmatched: array<string, mixed>}
     */
    private function observe(ParameterDefinition $definition, array $years, array $match, int $formYear, MinimumWages $wages): array
    {
        $group = static fn (string $name): ?int => isset($match[$name]) && $match[$name] !== '' ? (int) preg_replace('/\D/', '', $match[$name]) : null;
        $percent = $group('percent');

        if ($percent !== null) {
            return ['year' => $formYear + $years[0], 'number' => $percent, 'wage' => null, 'checks' => []];
        }

        $count = $group('wages');
        $wage = $group('wage');
        $amount = $group('amount');

        if ($count === null && $amount !== null) {
            // An amount alone: counted in a minimum wage of the year it is about, 1 January's first.
            $year = $formYear + $years[0];
            $during = $wages->during($year);

            if ($during === []) {
                return ['unmatched' => ['kind' => ParameterCheck::NO_WAGE_FOR_YEAR->value, 'year' => $year, 'amount' => $amount]];
            }

            foreach ($during as $yearWage) {
                if ($amount % $yearWage === 0) {
                    return ['year' => $year, 'number' => intdiv($amount, $yearWage), 'wage' => $yearWage, 'checks' => self::notJanuary($yearWage, $year, $wages)];
                }
            }

            return ['unmatched' => ['kind' => ParameterCheck::NOT_WHOLE_WAGES->value, 'year' => $year, 'amount' => $amount, 'wage' => $during[0]]];
        }

        if ($count === null || $count === 0) {
            return ['unmatched' => ['kind' => ParameterCheck::NOT_WHOLE_WAGES->value, 'year' => $formYear + $years[0]]];
        }

        if ($wage === null && $amount !== null) {
            if ($amount % $count !== 0) {
                return ['unmatched' => ['kind' => ParameterCheck::NOT_WHOLE_WAGES->value, 'year' => $formYear + $years[0], 'amount' => $amount, 'wages' => $count]];
            }

            $wage = intdiv($amount, $count);
        }

        if ($wage === null) {
            return ['year' => $formYear + $years[0], 'number' => $count, 'wage' => null, 'checks' => []];
        }

        foreach ($years as $offset) {
            if ($wages->onFirstOfJanuary($formYear + $offset) === $wage) {
                return ['year' => $formYear + $offset, 'number' => $count, 'wage' => $wage, 'checks' => []];
            }
        }

        foreach ($years as $offset) {
            if (in_array($wage, $wages->during($formYear + $offset), true)) {
                return ['year' => $formYear + $offset, 'number' => $count, 'wage' => $wage, 'checks' => self::notJanuary($wage, $formYear + $offset, $wages)];
            }
        }

        $year = $formYear + $years[0];

        return ['year' => $year, 'number' => $count, 'wage' => $wage, 'checks' => [[
            'kind' => ParameterCheck::WAGE_DIFFERS->value,
            'form_wage' => $wage,
            'decision_wage' => $wages->onFirstOfJanuary($year),
        ]]];
    }

    /**
     * The year's observations as values: one per rate or number said, or — for thresholds — the
     * thresholds together, "6, 12 and 24 minimum wages".
     *
     * @param  list<array{number: int, quote: string, checks: list<array<string, mixed>>}>  $observations
     * @return list<Reading>
     */
    private function readingsFor(ParameterDefinition $definition, int $year, array $observations, FormText $form): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $until = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        $origin = static fn (array $quotes): array => [
            'kind' => 'form',
            'form' => $form->structure->form_code,
            'structure_id' => $form->structure->id,
            'url' => $form->structure->url,
            'form_year' => $form->structure->form_year,
            'quotes' => array_values(array_unique($quotes)),
        ];
        $checks = static fn (array $of): array => array_values(array_unique(array_merge(...array_column($of, 'checks')), SORT_REGULAR));

        if ($definition->type === FiscalParameterValueType::JSON) {
            $numbers = array_values(array_unique(array_column($observations, 'number')));
            sort($numbers);

            return [new Reading($definition->code, $from, $until, $definition->value($numbers), $numbers, $origin(array_column($observations, 'quote')), $checks($observations))];
        }

        $readings = [];

        foreach (array_unique(array_column($observations, 'number')) as $number) {
            $same = array_values(array_filter($observations, static fn (array $observation): bool => $observation['number'] === $number));

            $readings[] = new Reading(
                $definition->code,
                $from,
                $until,
                $definition->value([$number]),
                [$number],
                $origin(array_column($same, 'quote')),
                $checks($same),
            );
        }

        return $readings;
    }

    /**
     * A wage that was in force during the year but not on 1 January: said on the value, for a person
     * to see which of the year's wages the form counted in.
     *
     * @return list<array<string, mixed>>
     */
    private static function notJanuary(int $wage, int $year, MinimumWages $wages): array
    {
        $january = $wages->onFirstOfJanuary($year);

        return $january === $wage ? [] : [['kind' => ParameterCheck::WAGE_DIFFERS->value, 'form_wage' => $wage, 'decision_wage' => $january]];
    }

    /** A formula as quoted beside its value, on one line. */
    private static function quote(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
