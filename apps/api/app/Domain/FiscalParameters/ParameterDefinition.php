<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters;

use Kern\Legislation\Enums\FiscalParameterValueType;

/** One value looked for, as `config/fiscal_parameters.php` describes it. */
final readonly class ParameterDefinition
{
    public function __construct(
        public string $code,
        /** The tax it belongs to — `RO.INCOME_TAX` — whose rules are read from the same part of the law. */
        public string $tax,
        public FiscalParameterValueType $type,
        /** Who owes what this value is part of: a person, a company, or both. */
        public ParameterSubject $subject,
        /**
         * The parts of the law it is read in, from its table of contents — "Titlul V Capitolul III".
         * More than one where the law states a rule in more than one place: the base of the health
         * contribution for independent activities has its cap in one section and its floor in the
         * next, which is about declaring and paying it.
         *
         * @var list<string>
         */
        public array $lawWithin,
        /** What is asked of the law, in the words of the law — "Care este cota de impozit pe venit?". */
        public ?string $question,
        /** What an answer looks like: one number, several, a percentage, an amount in lei. */
        public ParameterAnswer $answer,
    ) {
    }

    /** Whether the law is read for it — every value but the minimum wage, whose decision is its source. */
    public function isInLaw(): bool
    {
        return $this->lawWithin !== [] && $this->question !== null;
    }

    /**
     * A value as it is stored, from the numbers the law states: a rate "0.10" from 10 percent, a
     * threshold `{value: 12}`, thresholds `{wages: [6, 12, 24]}`, an amount `{amount_minor: …}`.
     *
     * @param  list<int>  $numbers  percentages in hundredths, wages and lei as stated
     * @return array<string, mixed>
     */
    public function value(array $numbers, bool $range = false, string $currency = 'RON'): array
    {
        return match ($this->type) {
            FiscalParameterValueType::DECIMAL => ['value' => sprintf('%d.%02d', intdiv($numbers[0], 100), $numbers[0] % 100)],
            FiscalParameterValueType::JSON => $range
                ? ['floor' => $numbers[0], 'cap' => $numbers[1] ?? $numbers[0]]
                : ['wages' => $numbers],
            FiscalParameterValueType::MONEY => ['amount_minor' => $numbers[0] * 100, 'currency' => $currency],
            default => ['value' => $numbers[0]],
        };
    }

    /**
     * The numbers behind a value as stored — the inverse of {@see value()}, for checking that a
     * paragraph states them.
     *
     * @param  array<string, mixed>  $value
     * @return list<int>
     */
    public function lawValues(array $value): array
    {
        return match ($this->type) {
            FiscalParameterValueType::DECIMAL => [(int) round((float) (is_string($value['value'] ?? null) ? $value['value'] : 0) * 100)],
            // What it is read as is what it was saved as: a base with two ends carries them, and a
            // rule the law has set both ways — CASS on independent income — is one shape in one year
            // and the other in the next.
            FiscalParameterValueType::JSON => is_numeric($value['floor'] ?? null) || is_numeric($value['cap'] ?? null)
                ? array_values(array_map(static fn (int|float|string $wages): int => (int) $wages, array_filter([$value['floor'] ?? null, $value['cap'] ?? null], static fn (mixed $wages): bool => is_numeric($wages))))
                : array_values(array_map(static fn (mixed $wages): int => is_numeric($wages) ? (int) $wages : 0, is_array($value['wages'] ?? null) ? $value['wages'] : [])),
            FiscalParameterValueType::INTEGER => is_int($value['value'] ?? null) ? [$value['value']] : [],
            FiscalParameterValueType::MONEY => is_int($value['amount_minor'] ?? null) ? [intdiv($value['amount_minor'], 100)] : [],
            FiscalParameterValueType::DATE => [],
        };
    }
}
