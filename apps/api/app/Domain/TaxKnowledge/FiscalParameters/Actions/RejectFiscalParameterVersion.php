<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;

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
