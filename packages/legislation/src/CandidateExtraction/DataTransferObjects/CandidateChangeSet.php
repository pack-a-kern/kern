<?php

declare(strict_types=1);

namespace Kern\Legislation\CandidateExtraction\DataTransferObjects;

/**
 * The full `BLUEPRINT.md` §9.2 AI candidate-extraction payload, once validated and parsed by
 * {@see \Kern\Legislation\CandidateExtraction\CandidateChangeSetParser}. `docs/AGENTS.md`
 * §9 applies to everything this carries: a `CandidateChangeSet` is a *proposal*, never
 * `status: APPROVED`, and this module never persists one as a `fiscal_parameter_versions`/
 * `tax_rule_versions` row automatically — see `docs/handoff/018-legislation-ingestion.md`
 * "What is NOT done".
 */
final readonly class CandidateChangeSet
{
    /**
     * @param list<CandidateChange> $candidateChanges
     */
    public function __construct(
        public string $documentVersionId,
        public array $candidateChanges,
    ) {
    }
}
