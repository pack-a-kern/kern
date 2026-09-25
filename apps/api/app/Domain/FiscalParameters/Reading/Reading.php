<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Reading;

use App\Domain\FiscalParameters\ParameterCheck;
use DateTimeImmutable;

/**
 * One value as a source states it, for one period — before the law is searched for it.
 *
 * `lawValues` are the numbers the law is searched for: a rate in percent, the minimum wages of a
 * threshold. A minimum wage has none: its decision is its source (`sourceSectionId`).
 */
final readonly class Reading
{
    /**
     * @param  array<string, mixed>  $value  as `fiscal_parameter_versions.value_json` holds it
     * @param  list<int>  $lawValues
     * @param  array<string, mixed>  $origin  what it was read from — a form's field, a decision's paragraph
     * @param  list<array<string, mixed>>  $checks
     */
    public function __construct(
        public string $parameterCode,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public array $value,
        public array $lawValues,
        public array $origin,
        public array $checks = [],
        public ?string $sourceSectionId = null,
    ) {
    }

    /** The same value for the same period, whatever it was read from. */
    public function key(): string
    {
        return implode('|', [$this->parameterCode, $this->validFrom->format('Y-m-d'), $this->validUntil?->format('Y-m-d') ?? '', json_encode($this->value, JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string, mixed> $detail */
    public function withCheck(ParameterCheck $check, array $detail = []): self
    {
        return new self(
            $this->parameterCode,
            $this->validFrom,
            $this->validUntil,
            $this->value,
            $this->lawValues,
            $this->origin,
            [...$this->checks, ['kind' => $check->value, ...$detail]],
            $this->sourceSectionId,
        );
    }
}
