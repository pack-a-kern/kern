<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

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

        foreach ($forms as $form) {
            $values = $this->formValues->read($form, $wages);
            $unmatched = $values->unmatched;
            $wagesSeen = [...$wagesSeen, ...$values->wagesSeen];

            foreach ($values->readings as $reading) {
                $matches = $this->law->find($this->definition($reading), $reading->lawValues, $reading->validFrom);

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
            ...array_map(fn (Reading $reading): array => ['reading' => $this->withFormWages($reading, $wagesSeen), 'matches' => []], $wageReadings),
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
     * A minimum wage with what the forms say of it: each form formula for a year it is in force on
     * 1 January either counts in this same wage or in another.
     *
     * @param  list<array{year: int, wage: int, url: string}>  $wagesSeen
     */
    private function withFormWages(Reading $reading, array $wagesSeen): Reading
    {
        $minor = $reading->value['amount_minor'] ?? null;
        $amount = is_int($minor) ? intdiv($minor, 100) : 0;

        foreach ($wagesSeen as $seen) {
            $day = new DateTimeImmutable(sprintf('%04d-01-01', $seen['year']));

            if ($reading->validFrom > $day || ($reading->validUntil !== null && $reading->validUntil < $day)) {
                continue;
            }

            $reading = $reading->withCheck(
                $seen['wage'] === $amount ? ParameterCheck::CONFIRMED_BY_FORM : ParameterCheck::DIFFERS_FROM_FORM,
                ['year' => $seen['year'], 'form_wage' => $seen['wage'], 'url' => $seen['url']],
            );
        }

        return $reading;
    }

    private function definition(Reading $reading): ParameterDefinition
    {
        return $this->catalog->find($reading->parameterCode) ?? $this->catalog->minimumWage();
    }
}
