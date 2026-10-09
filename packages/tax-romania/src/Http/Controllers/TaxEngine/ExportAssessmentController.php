<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\TaxEngine;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kern\TaxRomania\TaxEngine\Exports\AssessmentCsv;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * `GET .../tax/assessments/export?fiscal_year=…&part=figures|evidence` — the assessment as a CSV.
 *
 * Two parts rather than one file: the figures are what a person sends an accountant, the evidence is what
 * answers "where did that income come from". Separate downloads because they have different columns and
 * different audiences, and a spreadsheet with two tables stacked in one sheet is neither.
 *
 * **Recorded in the audit chain.** Nothing here is decrypted — the evidence names splits by id rather than
 * carrying counterparties — but a year's figures and their sources leaving the system is still an event
 * somebody may need to account for later, and the chain is where that belongs.
 */
final class ExportAssessmentController
{
    public function __invoke(Request $request, string $subject, AssessmentCsv $csv, WalletAudit $audit): Response
    {
        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'min:2018', 'max:2100'],
            'part' => ['required', 'string', 'in:figures,evidence'],
        ]);

        $year = (int) $data['fiscal_year'];
        $part = $data['part'];

        $assessment = TaxAssessment::query()
            ->where('tax_subject_id', $subject)
            ->where('fiscal_year', $year)
            // One row per year, draft or final; there is nothing to order between.
            ->firstOrFail();

        $audit->record(
            subjectId: $subject,
            actorId: $this->actorId($request),
            eventType: 'tax.assessment.exported',
            resourceType: 'tax_assessment',
            resourceId: $assessment->id,
            payload: ['fiscal_year' => $year, 'part' => $part],
        );

        $body = $part === 'figures' ? $csv->figures($assessment) : $csv->evidence($assessment);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"kern-{$year}-{$part}.csv\"",
        ]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
