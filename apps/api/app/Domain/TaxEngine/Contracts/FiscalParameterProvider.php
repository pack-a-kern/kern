<?php

declare(strict_types=1);

namespace App\Domain\TaxEngine\Contracts;

use App\Domain\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use App\Domain\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use App\Domain\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use App\Domain\TaxEngine\Exceptions\UnknownFiscalParameterException;
use DateTimeImmutable;

/**
 * The `fiscal_parameter_versions` counterpart of {@see TaxRuleVersionProvider} — same reasoning
 * for why this port exists, same "exactly one match or an exception" contract, same exclusion of
 * `REJECTED`/`SUPERSEDED` from the candidate set.
 *
 * `App\Domain\FiscalParameters\Providers\EloquentFiscalParameterProvider` is, today, the only
 * implementation — bound alongside {@see TaxRuleVersionProvider} in
 * `Kern\Legislation\TaxKnowledgeServiceProvider`. See
 * `docs/adr/0010-tax-engine-ports-and-adapters.md`.
 */
interface FiscalParameterProvider
{
    /**
     * @throws UnknownFiscalParameterException no `fiscal_parameters` row carries this code
     * @throws NoFiscalParameterVersionInForceException zero CANDIDATE/APPROVED versions cover the date
     * @throws AmbiguousFiscalParameterVersionException more than one version's effective range covers the date
     */
    public function versionInForceOn(string $parameterCode, DateTimeImmutable $date): ResolvedFiscalParameter;
}
