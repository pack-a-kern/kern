<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Query;

/**
 * The unknown-date rule, structurally enforced (`docs/handoff/029-hybrid-retrieval.md`): a caller
 * receives two separate lists, never one merged list with a flag easy to overlook. `$confirmed`
 * is safe to present as "this applied on the requested date." `$dateUnknown` matched the query but
 * carries no verifiable applicability for that date and must be presented as such — never silently
 * merged into `$confirmed`, and never silently dropped. A row whose *known* dates place it outside
 * the requested date appears in neither list — that is a confirmed non-match, not an unknown one.
 */
final readonly class HybridSearchResult
{
    /**
     * @param list<RetrievalResult> $confirmed
     * @param list<RetrievalResult> $dateUnknown
     */
    public function __construct(
        public array $confirmed,
        public array $dateUnknown,
    ) {
    }
}
