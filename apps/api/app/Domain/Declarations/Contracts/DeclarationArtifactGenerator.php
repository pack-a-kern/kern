<?php

declare(strict_types=1);

namespace App\Domain\Declarations\Contracts;

use App\Domain\Declarations\DataTransferObjects\GeneratedArtifact;

/**
 * The pluggable seam `docs/STATUS.md` blocked item 2 exists for: **the D212 submission artifact
 * format the ANAF portal accepts is not established** (`docs/reference/anaf-spv-integration.md`
 * — no documented D212 submission API, format unverified). This module must never guess it.
 *
 * Every part of `App\Domain\Declarations` that needs a generated artifact (`App\Domain\
 * Declarations\Actions\ValidateDeclaration`, at the moment a declaration is frozen to `VALID`)
 * depends on this interface, never on a concrete generator — swapping in a real, verified
 * generator once the format is obtained (M10's job, or whoever gets ANAF's specification) changes
 * exactly one container binding (`App\Domain\Declarations\DeclarationsServiceProvider`) and
 * nothing else in this module.
 *
 * The one implementation this task ships, {@see \App\Domain\Declarations\ArtifactGeneration\
 * PlaceholderArtifactGenerator}, is explicitly and unmistakably **not** that real generator
 * (`docs/AGENTS.md` §2 — never a placeholder that reads as real).
 */
interface DeclarationArtifactGenerator
{
    /**
     * @param array<string, mixed> $snapshotPayload the same structure
     *                                                `App\Domain\Declarations\Services\
     *                                                DeclarationSnapshotBuilder::build()` produces
     */
    public function generate(array $snapshotPayload): GeneratedArtifact;
}
