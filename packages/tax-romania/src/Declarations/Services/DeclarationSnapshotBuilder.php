<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Services;

use Kern\TaxRomania\Declarations\DataTransferObjects\AssessmentSnapshot;
use Kern\TaxRomania\Declarations\DataTransferObjects\GeneratedArtifact;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;

/**
 * Builds the frozen payload `BLUEPRINT.md` §12.3 requires at the moment a declaration becomes
 * `VALID`: the input assessment's own reproducibility hash (see `Kern\TaxRomania\Declarations\
 * Repositories\TaxAssessmentSnapshotReader`'s docblock for why this module cannot independently
 * recover a raw fact-id list beyond that hash), the ruleset id, the assessment id, the form
 * version id, and the generated artifact's hash — persisted verbatim to
 * `declarations.snapshot_json`, with `declarations.snapshot_hash` a SHA-256 digest *of* this exact
 * array, so "why is this hash" is always answerable by reading the row, not only at the moment it
 * was computed (the same reasoning `docs/handoff/020-tax-engine.md`'s `calculation_trace_json`
 * addition gives for itself).
 *
 * Two steps, not one: `Kern\TaxRomania\Declarations\Contracts\DeclarationArtifactGenerator::generate()`
 * needs the snapshot's *content* to produce an artifact, but the artifact's own hash is itself
 * part of what gets frozen — {@see self::buildBase()} produces everything except the artifact
 * fields, the generator consumes that, and {@see self::finalize()} adds the artifact's hash/format
 * and computes the final digest.
 *
 * Keys are sorted (top level and within `assessment_totals`) before hashing so the digest depends
 * only on the *content*, never on PHP array insertion order — the same reproducibility discipline
 * `Kern\TaxRomania\TaxEngine\Services\TaxAssessor::sorted()` already applies to its own
 * `input_snapshot_hash`.
 */
final class DeclarationSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildBase(Declaration $declaration, AssessmentSnapshot $assessment, FormVersion $formVersion): array
    {
        $totals = $assessment->totals;
        ksort($totals);

        $base = [
            'assessment_id' => $assessment->id,
            'assessment_input_snapshot_hash' => $assessment->inputSnapshotHash,
            'assessment_ruleset_id' => $assessment->rulesetId,
            'assessment_totals' => $totals,
            'declaration_fiscal_year' => $declaration->fiscal_year,
            'declaration_id' => $declaration->id,
            'declaration_kind' => $declaration->kind->value,
            'form_definition_id' => $formVersion->form_definition_id,
            'form_version_code' => $formVersion->version_code,
            'form_version_id' => $formVersion->id,
            'tax_subject_id' => $declaration->tax_subject_id,
        ];

        ksort($base);

        return $base;
    }

    /**
     * @param array<string, mixed> $base the output of {@see self::buildBase()}
     *
     * @return array{payload: array<string, mixed>, hash: string}
     */
    public function finalize(array $base, GeneratedArtifact $artifact): array
    {
        $payload = $base + [
            'artifact_format' => $artifact->format,
            'artifact_sha256' => $artifact->sha256,
        ];

        ksort($payload);

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return ['payload' => $payload, 'hash' => $hash];
    }
}
