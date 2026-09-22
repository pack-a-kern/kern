<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;
use App\Domain\TaxKnowledge\FiscalParameters\Forms\FormStructureSource;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatcher;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCheck;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterDefinition;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\FormValueReader;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\MinimumWageReader;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\MinimumWages;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\FiscalParameters\Recording\CandidateRecorder;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Reads every fiscal value there is to read, and proposes each one found in the law.
 *
 * 1. The minimum wage, from the Government's decisions already read in — the wage the forms count in.
 * 2. With `$forms`, every D212 structure file ANAF lists, each formula as a value for its year,
 *    checked against that year's wage.
 * 3. Each value looked for in the law in force when it starts; what the law does not say is kept on
 *    its form, for a person to see, and never becomes a value.
 * 4. The rest recorded as candidates — nothing counts until a person approves it.
 *
 * Only one runs at a time: the task started from the screen, and the one a newly read minimum-wage
 * decision starts on its own (`$forms` off, the forms unchanged).
 */
final readonly class ReadFiscalParameters
{
    public function __construct(
        private ParameterCatalog $catalog,
        private MinimumWageReader $minimumWages,
        private FormStructureSource $forms,
        private FormValueReader $formValues,
        private LawMatcher $law,
        private CandidateRecorder $recorder,
        private ConfigRepository $config,
    ) {
    }

    public function __invoke(bool $forms = true): ReadFiscalParametersResult
    {
        $result = Cache::lock('fiscal-parameters:read', $this->config->integer('fiscal_parameters.lock_seconds'))
            ->block($this->config->integer('fiscal_parameters.lock_seconds'), fn (): ReadFiscalParametersResult => $this->read($forms));

        return $result instanceof ReadFiscalParametersResult ? $result : $this->read($forms);
    }

    private function read(bool $readForms): ReadFiscalParametersResult
    {
        $wageReadings = $this->minimumWages->read();
        $wages = new MinimumWages($wageReadings);
        $formReadings = [];
        $wagesSeen = [];
        $unmatchedCount = 0;
        $forms = $readForms ? $this->forms->read('D212') : [];

        $read = [];

        foreach ($forms as $form) {
            $read[] = [$form, $this->formValues->read($form, $wages)];
        }

        $together = $this->thresholdsTogether(array_merge(...array_map(static fn (array $pair): array => $pair[1]->readings, $read)));

        foreach ($read as [$form, $values]) {
            $unmatched = $values->unmatched;
            $wagesSeen = [...$wagesSeen, ...$values->wagesSeen];

            foreach ($values->readings as $reading) {
                $reading = $together[$reading->key()] ?? $reading;
                $matches = $this->law->find($this->definition($reading), $reading->lawValues, $reading->validFrom, $reading->validUntil);

                if ($matches === []) {
                    $unmatched[] = [
                        'parameter' => $reading->parameterCode,
                        'kind' => ParameterCheck::NOT_IN_LAW->value,
                        'year' => (int) $reading->validFrom->format('Y'),
                        'numbers' => implode(', ', $reading->lawValues),
                        'quote' => implode(' · ', array_filter(is_array($reading->origin['quotes'] ?? null) ? $reading->origin['quotes'] : [], 'is_string')),
                    ];

                    continue;
                }

                $formReadings[] = ['reading' => $reading, 'matches' => $matches];
            }

            if ($unmatched !== []) {
                $form->structure->forceFill(['unmatched' => [...($form->structure->unmatched ?? []), ...$unmatched]])->save();
                $unmatchedCount += count($unmatched);
            }
        }

        $found = [
            ...array_map(fn (Reading $reading): array => ['reading' => $this->withFormWages($reading, $wagesSeen, $wages), 'matches' => []], $wageReadings),
            ...$formReadings,
        ];

        $replacing = $readForms
            ? array_map(static fn (ParameterDefinition $definition): string => $definition->code, $this->catalog->all())
            : [$this->catalog->minimumWage()->code];

        return new ReadFiscalParametersResult(
            minimumWages: count($wageReadings),
            forms: count($forms),
            candidates: $this->recorder->record($found, $replacing),
            notInLaw: $unmatchedCount,
        );
    }

    /**
     * Thresholds read for the same parameter and year, from different files, as one list: the 2024
     * file's estimate names only the least of CAS's for 2024, "12 salarii minime", the 2025 file both,
     * 12 and 24 — the same rule, read more or less fully, not two values for it.
     *
     * @param  list<Reading>  $readings
     * @return array<string, Reading> a reading's key => the same reading with every threshold read for its year
     */
    private function thresholdsTogether(array $readings): array
    {
        $numbers = [];

        foreach ($readings as $reading) {
            if ($this->definition($reading)->type === FiscalParameterValueType::JSON) {
                $slot = $reading->parameterCode . '|' . $reading->validFrom->format('Y-m-d');
                $numbers[$slot] = array_values(array_unique([...($numbers[$slot] ?? []), ...$reading->lawValues]));
            }
        }

        $together = [];

        foreach ($readings as $reading) {
            $all = $numbers[$reading->parameterCode . '|' . $reading->validFrom->format('Y-m-d')] ?? null;

            if ($all !== null) {
                sort($all);
                $together[$reading->key()] = new Reading($reading->parameterCode, $reading->validFrom, $reading->validUntil, $this->definition($reading)->value($all), $all, $reading->origin, $reading->checks);
            }
        }

        return $together;
    }

    /**
     * A minimum wage with what the forms say of it.
     *
     * A form counts a year's income in one wage. Where the wage changed during that year — 2 230 lei
     * until 12 January 2021, 2 300 from the 13th — the form counting in 2 300 confirms the 2 300
     * decision and says nothing against the 2 230 one: both were that year's. Only a wage no
     * decision set for the year is a disagreement, noted on the wage of its 1 January.
     *
     * @param  list<array{year: int, wage: int, url: string, form_year: int}>  $wagesSeen
     */
    private function withFormWages(Reading $reading, array $wagesSeen, MinimumWages $wages): Reading
    {
        $minor = $reading->value['amount_minor'] ?? null;
        $amount = is_int($minor) ? intdiv($minor, 100) : 0;

        foreach (array_unique($wagesSeen, SORT_REGULAR) as $seen) {
            $start = new DateTimeImmutable(sprintf('%04d-01-01', $seen['year']));
            $end = new DateTimeImmutable(sprintf('%04d-12-31', $seen['year']));
            $detail = ['year' => $seen['year'], 'form_year' => $seen['form_year'], 'form_wage' => $seen['wage'], 'url' => $seen['url']];

            if ($reading->validFrom > $end || ($reading->validUntil !== null && $reading->validUntil < $start)) {
                continue;
            }

            if ($seen['wage'] === $amount) {
                $reading = $reading->withCheck(ParameterCheck::CONFIRMED_BY_FORM, $detail);
            } elseif (! in_array($seen['wage'], $wages->during($seen['year']), true) && $wages->onFirstOfJanuary($seen['year']) === $amount) {
                $reading = $reading->withCheck(ParameterCheck::DIFFERS_FROM_FORM, $detail);
            }
        }

        return $reading;
    }

    private function definition(Reading $reading): ParameterDefinition
    {
        return $this->catalog->find($reading->parameterCode) ?? $this->catalog->minimumWage();
    }
}
