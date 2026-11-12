<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Kern\Legislation\Http\Requests\SearchLegislationRequest;
use Kern\Legislation\Retrieval\Query\HybridLegislationSearch;
use Kern\Legislation\Retrieval\Query\RetrievalResult;

/**
 * `GET /api/v1/legislation/search` — hybrid search over ingested legislation (`BLUEPRINT.md` §15.2).
 *
 * The two result groups are kept separate all the way to the screen rather than merged and sorted
 * by score, because they answer different questions. `confirmed` results have effective dates that
 * cover the requested date. `date_unknown` results have no effective dates at all — which is most
 * of this corpus, since `legislatie.just.ro` publishes no machine-readable effective date and every
 * version therefore lands `REQUIRES_REVIEW`.
 *
 * Presenting the second group as though it were the first would be the system asserting that a text
 * was in force on a date nobody has established. A third case does not reach here at all: a section
 * whose *known* dates exclude the requested date is dropped by the query, because that is a
 * confirmed non-match rather than an unknown.
 *
 * Narrowed, when asked, to the laws the laws screen shows under the same filter — a `type`, a `year`,
 * a `watch` — so one filter decides which laws are listed and which are searched.
 *
 * Gated by `auth:web` and a per-account throttle, nothing more. Legislation is public law, not tenant
 * data — there is no `{subject}` and nothing here is scoped to one. It is gated at all because an
 * endpoint that runs an embedding model per call is an easy way to burn a server.
 */
final class SearchLegislationController
{
    public function __invoke(SearchLegislationRequest $request, HybridLegislationSearch $search): JsonResponse
    {
        $result = $search->search($request->searchQuery());

        return response()->json([
            'as_of' => $request->asOf()->format('Y-m-d'),
            'confirmed' => array_map($this->present(...), $result->confirmed),
            'date_unknown' => array_map($this->present(...), $result->dateUnknown),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(RetrievalResult $hit): array
    {
        return [
            'chunk_id' => $hit->chunkId,
            'section_id' => $hit->sectionId,
            'source_document_id' => $hit->sourceDocumentId,
            'source_code' => $hit->sourceCode,
            'document_type' => $hit->documentType,
            'issuer' => $hit->issuer,
            'citation' => $hit->citationLabel,
            'effective_from' => $hit->effectiveFrom?->format('Y-m-d'),
            'effective_until' => $hit->effectiveUntil?->format('Y-m-d'),
            'publication_date' => $hit->publicationDate?->format('Y-m-d'),
            'text' => $hit->text,
            'chunk_index' => $hit->chunkIndex,
            'chunk_count' => $hit->chunkCount,
            // Both halves of the hybrid score, not just the total: an operator tuning retrieval
            // needs to see whether a hit came from wording or from meaning. `semantic` is null
            // when no embedding provider answered — the search still works lexically, and saying
            // so is more useful than reporting a zero.
            'lexical_rank' => round($hit->lexicalRank, 6),
            'semantic_similarity' => $hit->semanticSimilarity === null ? null : round($hit->semanticSimilarity, 6),
            'score' => round($hit->combinedScore, 6),
        ];
    }
}
