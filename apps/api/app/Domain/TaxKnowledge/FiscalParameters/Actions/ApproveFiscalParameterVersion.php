<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Actions;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use Illuminate\Support\Facades\DB;

/**
 * A person approves a candidate, on the paragraph of the law they chose among those found.
 *
 * Whatever else was in force for that parameter in the same period — another candidate, an earlier
 * approval — is superseded in the same step, so on any day a parameter has one answer
 * (`EloquentFiscalParameterProvider` refuses two).
 *
 * The one place a fiscal value becomes `APPROVED`; see
 * {@see \App\Domain\TaxKnowledge\Concerns\ForbidsAgentApproval}.
 */
final readonly class ApproveFiscalParameterVersion
{
    public function __invoke(string $versionId, string $sectionId, string $userId): FiscalParameterVersion
    {
        return DB::transaction(static function () use ($versionId, $sectionId, $userId): FiscalParameterVersion {
            $version = FiscalParameterVersion::query()->lockForUpdate()->findOrFail($versionId);

            if ($version->status !== RuleApprovalStatus::CANDIDATE) {
                throw FiscalParameterDecisionException::notACandidate($versionId);
            }

            $found = array_column($version->evidence['matches'] ?? [], 'section_id');

            if ($sectionId !== $version->source_section_id && ! in_array($sectionId, $found, true)) {
                throw FiscalParameterDecisionException::notItsSource($versionId, $sectionId);
            }

            FiscalParameterVersion::query()
                ->where('fiscal_parameter_id', $version->fiscal_parameter_id)
                ->whereKeyNot($version->id)
                ->whereIn('status', [RuleApprovalStatus::CANDIDATE->value, RuleApprovalStatus::APPROVED->value])
                ->where(static function ($query) use ($version): void {
                    $query->whereNull('valid_until')->orWhere('valid_until', '>=', $version->valid_from->format('Y-m-d'));
                })
                ->when($version->valid_until !== null, static function ($query) use ($version): void {
                    $query->where('valid_from', '<=', $version->valid_until?->format('Y-m-d'));
                })
                ->update(['status' => RuleApprovalStatus::SUPERSEDED->value]);

            $version->source_section_id = $sectionId;
            $version->approveByPerson($userId);

            return $version;
        });
    }
}
