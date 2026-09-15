<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Repositories;

use App\Domain\Declarations\DataTransferObjects\AssessmentSnapshot;
use App\Domain\Declarations\Exceptions\AssessmentNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Reads `App\Domain\TaxEngine`'s `tax_assessments` table through the plain query builder — never
 * `App\Domain\TaxEngine\Models\TaxAssessment` (`docs/AGENTS.md` §6: a module never imports
 * another module's Eloquent model directly). `App\Domain\TaxEngine` already inverted its own
 * equivalent boundary with `TaxKnowledge` by defining ports the *other* module implements
 * (`docs/handoff/023-taxengine-boundary-inversion.md`) — doing the same here would require adding
 * an adapter class inside `app/Domain/TaxEngine/**`, outside this task's path scope
 * (`apps/api/app/Domain/Declarations/**` only). This class is instead the identical, already
 * sanctioned interim shape `App\Domain\Documents\Queries\ResolveTransactionImportState`
 * uses for reading `Domain/Wallet`'s `financial_transactions` table: a raw, read-only query
 * against a stable table this module does not own, confined to exactly this one class.
 *
 * **What this cannot do**: `tax_assessments` stores only `input_snapshot_hash` (a SHA-256 digest),
 * never the underlying `tax_facts` ids it was computed from. This reader — and therefore this
 * whole module — cannot independently recover "which facts backed this assessment"; it treats
 * `input_snapshot_hash` as the authoritative, already-reproducible reference to that input set.
 * See `docs/handoff/028-d212-declarations.md` "Open questions" for what a future task would need
 * to add to `Domain/TaxEngine` to change this.
 */
final class TaxAssessmentSnapshotReader
{
    public function find(string $assessmentId): AssessmentSnapshot
    {
        $row = DB::table('tax_assessments')->where('id', $assessmentId)->first();

        if ($row === null) {
            throw AssessmentNotFoundException::forId($assessmentId);
        }

        /** @var array<string, array{minor_units: int, currency: string}> $totals */
        $totals = json_decode((string) $row->totals_json, true, flags: JSON_THROW_ON_ERROR);

        return new AssessmentSnapshot(
            id: $row->id,
            taxSubjectId: $row->tax_subject_id,
            fiscalYear: (int) $row->fiscal_year,
            asOf: (string) $row->as_of,
            rulesetId: $row->ruleset_id,
            inputSnapshotHash: $row->input_snapshot_hash,
            status: $row->status,
            totals: $totals,
        );
    }
}
