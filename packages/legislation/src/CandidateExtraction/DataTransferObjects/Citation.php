<?php

declare(strict_types=1);

namespace Kern\Legislation\CandidateExtraction\DataTransferObjects;

/**
 * One `citations[]` entry from `BLUEPRINT.md` §9.2's candidate-change JSON contract.
 *
 * `quoteHash` is carried exactly as the schema example shows it — a hash of the quoted source
 * text, not the text itself. This module can therefore verify that `sectionId` exists
 * ({@see \Kern\Legislation\CandidateExtraction\CandidateCitationVerifier}) but cannot
 * verify the hash matches a real substring of that section's text, because the contract as
 * `BLUEPRINT.md` defines it never includes the quoted text itself — see
 * `docs/handoff/018-legislation-ingestion.md` "Open questions".
 */
final readonly class Citation
{
    public function __construct(
        public string $sectionId,
        public string $quoteHash,
    ) {
    }
}
