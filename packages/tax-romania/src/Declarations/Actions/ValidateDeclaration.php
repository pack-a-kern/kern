<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Actions;

use Kern\TaxRomania\Declarations\Contracts\DeclarationArtifactGenerator;
use Kern\TaxRomania\Declarations\Enums\DeclarationStatus;
use Kern\TaxRomania\Declarations\Exceptions\DeclarationValidationFailedException;
use Kern\TaxRomania\Declarations\Models\Declaration;
use Kern\TaxRomania\Declarations\Models\FormVersion;
use Kern\TaxRomania\Declarations\Repositories\TaxAssessmentSnapshotReader;
use Kern\TaxRomania\Declarations\Services\DeclarationLifecycle;
use Kern\TaxRomania\Declarations\Services\DeclarationSnapshotBuilder;
use Kern\TaxRomania\Declarations\Services\DeclarationValidator;

/**
 * `BLUEPRINT.md` §12.1's "Validator" step, and the moment `§12.3`'s snapshot is frozen.
 *
 * `DRAFT -> VALIDATING` is persisted first (a real, observable transient state, not merely an
 * in-memory marker) so a caller polling this row mid-validation sees the truth. On failure, the
 * declaration is explicitly moved back to `DRAFT` — never left stuck in `VALIDATING` — and
 * `DeclarationValidationFailedException` carries every issue found
 * (`Kern\TaxRomania\Declarations\Services\DeclarationValidator`), including, when it applies,
 * `ASSESSMENT_NOT_FINAL` — the safety property that keeps a declaration built on `CANDIDATE`
 * ruleset parameters from ever reaching `VALID`/`SUBMITTED` (`docs/AGENTS.md` §9).
 */
final readonly class ValidateDeclaration
{
    public function __construct(
        private DeclarationValidator $validator,
        private TaxAssessmentSnapshotReader $assessmentReader,
        private DeclarationSnapshotBuilder $snapshotBuilder,
        private DeclarationArtifactGenerator $artifactGenerator,
        private DeclarationLifecycle $lifecycle,
    ) {
    }

    public function __invoke(Declaration $declaration): Declaration
    {
        $this->lifecycle->transition($declaration, DeclarationStatus::VALIDATING);

        $result = $this->validator->validate($declaration);

        if (! $result->passed) {
            $this->lifecycle->transition($declaration, DeclarationStatus::DRAFT);

            throw new DeclarationValidationFailedException($declaration->id, $result->issues);
        }

        $assessment = $this->assessmentReader->find($declaration->assessment_id);

        /** @var FormVersion $formVersion */
        $formVersion = $declaration->formVersion()->firstOrFail();

        $base = $this->snapshotBuilder->buildBase($declaration, $assessment, $formVersion);
        $artifact = $this->artifactGenerator->generate($base);
        $final = $this->snapshotBuilder->finalize($base, $artifact);

        $this->lifecycle->transition($declaration, DeclarationStatus::VALID, [
            'snapshot_hash' => $final['hash'],
            'snapshot_json' => $final['payload'],
        ]);

        return $declaration;
    }
}
