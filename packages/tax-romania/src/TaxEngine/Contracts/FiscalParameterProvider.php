<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Contracts;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownFiscalParameterException;

/**
 * The `fiscal_parameter_versions` counterpart of {@see TaxRuleVersionProvider} — same reasoning
 * for why this port exists, same "exactly one match or an exception" contract, same exclusion of
 * `REJECTED`/`SUPERSEDED` from the candidate set.
 *
 * `Kern\TaxRomania\FiscalParameters\Providers\EloquentFiscalParameterProvider` is, today, the only
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

    /**
     * Whether that year's law was read for this parameter and found to state no value.
     *
     * The distinction this exists for: "nobody has read 2023 yet" and "2023's law was read and sets no rent
     * expense allowance" are different facts, and only the second is an answer. `fiscal_parameter_reads` records
     * which it is — the screen already draws a dash for one and an empty cell for the other — and the engine was
     * consulting neither, so a year the law deliberately left blank failed as though the dataset were
     * incomplete.
     *
     * False therefore means *unknown*, not *present*: a caller that has already failed to find a version and
     * gets false here is looking at a genuine gap and must still refuse.
     */
    public function readFoundNoValueFor(string $parameterCode, int $year): bool;
}
