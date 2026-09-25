<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Actions;

use App\Domain\FiscalParameters\FiscalParameterDecisionException;
use App\Domain\FiscalParameters\Models\FiscalParameterVersion;
use Kern\Legislation\Enums\RuleApprovalStatus;

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
