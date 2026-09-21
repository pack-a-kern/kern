<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Legislation;

use App\Domain\TaxKnowledge\Enums\TrustTier;
use App\Domain\TaxKnowledge\Retrieval\Query\HybridLegislationSearch;
use App\Domain\TaxKnowledge\Retrieval\Query\HybridSearchQuery;
use App\Domain\TaxKnowledge\Retrieval\Query\RetrievalResult;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
 * Gated by `auth:web` only. Legislation is public law, not tenant data — there is no `{subject}`
 * and nothing here is scoped to one. It is behind authentication at all because an unauthenticated
 * endpoint that runs an embedding model per call is an easy way to burn a server.
 */
final class SearchLegislationController extends Controller
{
    private const int MAX_RESULTS = 50;

    public function __invoke(Request $request, HybridLegislationSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:500'],
            // Defaults to today. Explicit rather than implied: which date a search is "as of"
            // decides which versions can match, and a silent `now()` would make the same query
            // return different law tomorrow with nothing recording why.
            'as_of' => ['sometimes', 'date'],
            'source' => ['sometimes', 'string', 'max:32'],
            'tier' => ['sometimes', Rule::enum(TrustTier::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_RESULTS],
        ]);

        $asOf = isset($validated['as_of']) && is_string($validated['as_of'])
            ? new DateTimeImmutable($validated['as_of'])
            : new DateTimeImmutable('today');

        $source = isset($validated['source']) && is_string($validated['source']) && $validated['source'] !== ''
            ? [$validated['source']]
            : null;

        $tier = isset($validated['tier']) && is_string($validated['tier'])
            ? [TrustTier::from($validated['tier'])]
            : null;

        $result = $search->search(new HybridSearchQuery(
            queryText: (string) $validated['q'],
            asOf: $asOf,
            sourceCodes: $source,
            trustTiers: $tier,
            limit: isset($validated['limit']) && is_int($validated['limit']) ? $validated['limit'] : 20,
        ));

        return response()->json([
            'as_of' => $asOf->format('Y-m-d'),
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
            'trust_tier' => $hit->trustTier->value,
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
