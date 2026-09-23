<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;

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
        /** The part of the law it is read from, from its table of contents — "Titlul V Capitolul III". */
        public ?string $lawWithin,
        /** What is asked of the law, in the words of the law — "Care este cota de impozit pe venit?". */
        public ?string $question,
        /** What an answer looks like: one number, several, a percentage, an amount in lei. */
        public ParameterAnswer $answer,
    ) {
    }

    /** Whether the law is read for it — every value but the minimum wage, whose decision is its source. */
    public function isInLaw(): bool
    {
        return $this->lawWithin !== null && $this->question !== null;
    }

    /**
     * A value as it is stored, from the numbers the law states: a rate "0.10" from 10 percent, a
     * threshold `{value: 12}`, thresholds `{wages: [6, 12, 24]}`, an amount `{amount_minor: …}`.
     *
     * @param  list<int>  $numbers  percentages in hundredths, wages and lei as stated
     * @return array<string, mixed>
     */
    public function value(array $numbers, string $currency = 'RON'): array
    {
        return match ($this->type) {
            FiscalParameterValueType::DECIMAL => ['value' => sprintf('%d.%02d', intdiv($numbers[0], 100), $numbers[0] % 100)],
            FiscalParameterValueType::JSON => ['wages' => $numbers],
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
            FiscalParameterValueType::JSON => array_values(array_map(static fn (mixed $wages): int => is_numeric($wages) ? (int) $wages : 0, is_array($value['wages'] ?? null) ? $value['wages'] : [])),
            FiscalParameterValueType::INTEGER => is_int($value['value'] ?? null) ? [$value['value']] : [],
            FiscalParameterValueType::MONEY => is_int($value['amount_minor'] ?? null) ? [intdiv($value['amount_minor'], 100)] : [],
            FiscalParameterValueType::DATE => [],
        };
    }
}
