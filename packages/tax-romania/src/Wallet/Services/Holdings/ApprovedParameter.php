<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Holdings;

use DateTimeImmutable;
use Kern\TaxRomania\TaxEngine\Contracts\FiscalParameterProvider;
use Kern\TaxRomania\TaxEngine\DataTransferObjects\ResolvedFiscalParameter;
use Kern\TaxRomania\TaxEngine\Enums\RuleApprovalStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\AmbiguousFiscalParameterVersionException;
use Kern\TaxRomania\TaxEngine\Exceptions\NoFiscalParameterVersionInForceException;
use Kern\TaxRomania\TaxEngine\Exceptions\UnknownFiscalParameterException;

/**
 * A fiscal value a holding's money is reckoned with — the dividend rate, a securities gain's — as a person
 * approved it for that day, or none: what is withheld is never worked out from a value nobody approved.
 */
final readonly class ApprovedParameter
{
    public function __construct(
        private FiscalParameterProvider $parameters,
    ) {
    }

    public function onDay(string $code, DateTimeImmutable $on): ?ResolvedFiscalParameter
    {
        try {
            $value = $this->parameters->versionInForceOn($code, $on);
        } catch (UnknownFiscalParameterException | NoFiscalParameterVersionInForceException | AmbiguousFiscalParameterVersionException) {
            return null;
        }

        return $value->status === RuleApprovalStatus::APPROVED ? $value : null;
    }
}
