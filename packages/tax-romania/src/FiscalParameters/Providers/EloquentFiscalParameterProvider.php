<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Providers;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\ParameterReadOutcome;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterRead;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\Enums\FiscalParameterValueType as EngineFiscalParameterValueType;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus as EngineApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownFiscalParameterException;

/**
 * The `fiscal_parameter_versions` counterpart of {@see EloquentTaxRuleVersionProvider} — same
 * reasoning for why this class, not `Kern\TaxRomania\TaxEngine`, reads `Kern\Legislation`
 * models directly here, same "exactly one match or an exception" contract, same exclusion of
 * `REJECTED`/`SUPERSEDED` from the candidate set. See
 * `docs/adr/0010-tax-engine-ports-and-adapters.md`.
 */
final readonly class EloquentFiscalParameterProvider implements FiscalParameterProvider
{
    public function versionInForceOn(string $parameterCode, DateTimeImmutable $date): ResolvedFiscalParameter
    {
        /** @var FiscalParameter|null $parameter */
        $parameter = FiscalParameter::query()->where('code', $parameterCode)->first();

        if ($parameter === null) {
            throw UnknownFiscalParameterException::forCode($parameterCode);
        }

        $dateString = $date->format('Y-m-d');

        /** @var Collection<int, FiscalParameterVersion> $candidates */
        $candidates = FiscalParameterVersion::query()
            ->where('fiscal_parameter_id', $parameter->id)
            ->whereIn('status', [ApprovalStatus::CANDIDATE->value, ApprovalStatus::APPROVED->value])
            ->where('valid_from', '<=', $dateString)
            ->where(function ($query) use ($dateString): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $dateString);
            })
            ->get();

        if ($candidates->count() === 0) {
            throw NoFiscalParameterVersionInForceException::forDate($parameterCode, $date);
        }

        // A value a person approved stands over one only proposed for the same day: reading the
        // sources again proposes candidates beside approved values, and must not make them ambiguous.
        $approved = $candidates->filter(static fn (FiscalParameterVersion $version): bool => $version->status === ApprovalStatus::APPROVED);
        $candidates = $approved->isEmpty() ? $candidates : $approved;

        if ($candidates->count() > 1) {
            /** @var list<string> $ids */
            $ids = $candidates->map(static fn (FiscalParameterVersion $version): string => $version->id)->all();

            throw AmbiguousFiscalParameterVersionException::forDate($parameterCode, $date, $ids);
        }

        /** @var FiscalParameterVersion $version */
        $version = $candidates->first();

        return new ResolvedFiscalParameter(
            parameterCode: $parameter->code,
            parameterVersionId: $version->id,
            valueType: EngineFiscalParameterValueType::from($parameter->value_type->value),
            valueJson: $version->value_json,
            validFrom: $version->valid_from->toDateTimeImmutable(),
            validUntil: $version->valid_until?->toDateTimeImmutable(),
            sourceSectionId: $version->source_section_id,
            status: EngineApprovalStatus::from($version->status->value),
        );
    }

    public function readFoundNoValueFor(string $parameterCode, int $year): bool
    {
        $parameter = FiscalParameter::query()->where('code', $parameterCode)->first();

        if (! $parameter instanceof FiscalParameter) {
            // A parameter this installation has never heard of. Not "the law says nothing" — nobody has asked.
            return false;
        }

        return FiscalParameterRead::query()
            ->where('fiscal_parameter_id', $parameter->id)
            ->where('year', $year)
            ->where('outcome', ParameterReadOutcome::NOT_IN_LAW->value)
            ->exists();
    }
}
