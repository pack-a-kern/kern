<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Actions;

use Kern\Legislation\Enums\RuleApprovalStatus;
use Kern\TaxRomania\FiscalParameters\FiscalParameterDecisionException;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;

/** A person rejects a candidate: it stays, as rejected, and is not proposed again for that period. */
final readonly class RejectFiscalParameterVersion
{
    public function __invoke(string $versionId): FiscalParameterVersion
    {
        $version = FiscalParameterVersion::query()->findOrFail($versionId);

        if ($version->status !== RuleApprovalStatus::CANDIDATE) {
            throw FiscalParameterDecisionException::notACandidate($versionId);
        }

        $version->forceFill(['status' => RuleApprovalStatus::REJECTED->value])->save();

        return $version;
    }
}
