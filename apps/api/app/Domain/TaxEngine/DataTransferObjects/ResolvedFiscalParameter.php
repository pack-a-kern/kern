<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\DataTransferObjects;

use App\Domain\Fx\ValueObjects\DecimalRate;
use App\Domain\TaxEngine\Enums\FiscalParameterValueType;
use App\Domain\TaxEngine\Enums\RuleApprovalStatus;
use App\Domain\TaxEngine\Exceptions\InvalidParameterValueShapeException;
use App\Domain\TaxEngine\ValueObjects\Money;
use DateTimeImmutable;

/**
 * One `fiscal_parameter_versions` row (`App\Domain\TaxKnowledge`), resolved for a specific date
 * and handed to a rule as a plain value — the output of `App\Domain\TaxEngine\Contracts\
 * FiscalParameterProvider::versionInForceOn()`. This DTO never names a TaxKnowledge type
 * (`docs/adr/0010-tax-engine-ports-and-adapters.md`): `$valueType`/`$status` are TaxEngine's own
 * enums, not TaxKnowledge's — the implementing adapter
 * (`App\Domain\TaxKnowledge\Providers\EloquentFiscalParameterProvider`) maps between the two.
 * `status` is carried through so `App\Domain\TaxEngine\Services\ParameterResolver` can enforce
 * `docs/AGENTS.md` §9's `CANDIDATE`-vs-`FINAL` gate; a rule itself never inspects `status`.
 */
final readonly class ResolvedFiscalParameter
{
    /**
     * @param array<string, mixed> $valueJson
     */
    public function __construct(
        public string $parameterCode,
        public string $parameterVersionId,
        public FiscalParameterValueType $valueType,
        public array $valueJson,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public string $sourceSectionId,
        public RuleApprovalStatus $status,
    ) {
    }

    public function asMoney(string $currencyCode, int $decimalPlaces = 2): Money
    {
        $this->assertType(FiscalParameterValueType::MONEY);

        $minorUnits = $this->valueJson['amount_minor'] ?? null;

        if (! is_int($minorUnits)) {
            throw InvalidParameterValueShapeException::forParameter($this->parameterCode, 'value_json.amount_minor must be an integer');
        }

        return Money::ofMinorUnits($minorUnits, $currencyCode, $decimalPlaces);
    }

    public function asDecimalRate(): DecimalRate
    {
        $this->assertType(FiscalParameterValueType::DECIMAL);

        $value = $this->valueJson['value'] ?? null;

        if (! is_string($value)) {
            throw InvalidParameterValueShapeException::forParameter($this->parameterCode, 'value_json.value must be a decimal string');
        }

        return DecimalRate::fromString($value);
    }

    public function asInteger(): int
    {
        $this->assertType(FiscalParameterValueType::INTEGER);

        $value = $this->valueJson['value'] ?? null;

        if (! is_int($value)) {
            throw InvalidParameterValueShapeException::forParameter($this->parameterCode, 'value_json.value must be an integer');
        }

        return $value;
    }

    public function asDate(): DateTimeImmutable
    {
        $this->assertType(FiscalParameterValueType::DATE);

        $value = $this->valueJson['value'] ?? null;

        if (! is_string($value)) {
            throw InvalidParameterValueShapeException::forParameter($this->parameterCode, 'value_json.value must be a date string');
        }

        return new DateTimeImmutable($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function asJson(): array
    {
        $this->assertType(FiscalParameterValueType::JSON);

        return $this->valueJson;
    }

    private function assertType(FiscalParameterValueType $expected): void
    {
        if ($this->valueType !== $expected) {
            throw InvalidParameterValueShapeException::forParameter(
                $this->parameterCode,
                sprintf('expected value_type %s, got %s', $expected->value, $this->valueType->value),
            );
        }
    }
}
