<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Actions;

use Kern\Platform\Approval\ApprovalStatus;
use Kern\TaxRomania\FiscalParameters\FiscalParameterDecisionException;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;

/** A person rejects a candidate: it stays, as rejected, and is not proposed again for that period. */
final readonly class RejectFiscalParameterVersion
{
    public function __invoke(string $versionId): FiscalParameterVersion
    {
        $version = FiscalParameterVersion::query()->findOrFail($versionId);

        if ($version->status !== ApprovalStatus::CANDIDATE) {
            throw FiscalParameterDecisionException::notACandidate($versionId);
        }

        $version->forceFill(['status' => ApprovalStatus::REJECTED->value])->save();

        return $version;
    }
}
