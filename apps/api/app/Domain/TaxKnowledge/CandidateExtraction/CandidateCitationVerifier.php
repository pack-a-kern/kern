<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\CandidateExtraction;

use App\Domain\TaxKnowledge\CandidateExtraction\DataTransferObjects\CandidateChangeSet;
use App\Domain\TaxKnowledge\CandidateExtraction\Exceptions\UnknownSourceReferenceException;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;

/**
 * The database-backed half of validating a {@see CandidateChangeSet}: does
 * `document_version_id` actually exist, does every cited `section_id` actually exist, and does
 * each cited section actually belong to the referenced document version. A structurally valid
 * ({@see CandidateChangeSetParser}) but factually hallucinated reference is rejected here, not
 * silently accepted.
 *
 * Deliberately does **not** verify a citation's `quote_hash` against real section text — the
 * `BLUEPRINT.md` §9.2 contract never carries the quoted text itself, only its hash, so there is
 * nothing here to hash and compare against. See `docs/handoff/018-legislation-ingestion.md`
 * "Open questions".
 */
final readonly class CandidateCitationVerifier
{
    public function verify(CandidateChangeSet $set): void
    {
        if (SourceDocumentVersion::query()->whereKey($set->documentVersionId)->doesntExist()) {
            throw UnknownSourceReferenceException::unknownDocumentVersion($set->documentVersionId);
        }

        foreach ($set->candidateChanges as $change) {
            foreach ($change->citations as $citation) {
                /** @var SourceSection|null $section */
                $section = SourceSection::query()->find($citation->sectionId);

                if ($section === null) {
                    throw UnknownSourceReferenceException::unknownSection($citation->sectionId);
                }

                if ($section->document_version_id !== $set->documentVersionId) {
                    throw UnknownSourceReferenceException::sectionBelongsToDifferentVersion(
                        $citation->sectionId,
                        $set->documentVersionId,
                        $section->document_version_id,
                    );
                }
            }
        }
    }
}
