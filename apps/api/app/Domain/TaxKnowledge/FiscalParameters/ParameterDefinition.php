<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters;

use App\Domain\TaxKnowledge\Enums\FiscalParameterValueType;

/** One value looked for, as `config/fiscal_parameters.php` describes it. */
final readonly class ParameterDefinition
{
    /**
     * @param  list<array{pattern: string, years: list<int>}>  $formPatterns
     */
    public function __construct(
        public string $code,
        public FiscalParameterValueType $type,
        /** The part of the law to search, from its table of contents — "Titlul V Capitolul III". */
        public ?string $lawWithin,
        /** What the paragraph says, as a search reads it, `{value}` standing for the value. */
        public ?string $lawSays,
        public array $formPatterns,
    ) {
    }

    /** Whether the law is searched for it — every value but the minimum wage, whose decision is its source. */
    public function isInLaw(): bool
    {
        return $this->lawWithin !== null && $this->lawSays !== null;
    }

    /**
     * A value as it is stored, from the number a source says: a rate "0.10" from 10 percent, a
     * threshold `{value: 12}`, thresholds `{wages: [6, 12, 24]}`.
     *
     * @param  list<int>  $numbers
     * @return array<string, mixed>
     */
    public function value(array $numbers): array
    {
        return match ($this->type) {
            FiscalParameterValueType::DECIMAL => ['value' => sprintf('%d.%02d', intdiv($numbers[0], 100), $numbers[0] % 100)],
            FiscalParameterValueType::JSON => ['wages' => $numbers],
            default => ['value' => $numbers[0]],
        };
    }

    /**
     * The numbers the law is searched for, from a value as stored — the inverse of {@see value()}.
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
            default => [],
        };
    }
}
