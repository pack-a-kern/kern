<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Query;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Enums\TrustTier;
use Kern\Legislation\Retrieval\Embedding\EmbeddingProvider;
use Kern\Legislation\Retrieval\Embedding\EmbeddingPurpose;
use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use RuntimeException;
use stdClass;

/**
 * Hybrid lexical + semantic + temporal/tier retrieval over `ext_source_section_chunks`, in **one**
 * SQL statement — `docs/adr/0006-pgvector-over-qdrant.md`'s entire reason for choosing pgvector
 * over a second vector store: "hybrid retrieval becomes a single SQL query per stage rather than
 * a cross-store merge." See `docs/handoff/029-hybrid-retrieval.md` for the exact query shape and
 * why each piece is there; this docblock covers the parts that would otherwise be re-litigated.
 *
 * **Temporal filtering (`BLUEPRINT.md` §15.2's hard requirement) happens inside the same query**,
 * and happens *first*: a row whose *known* effective range excludes `$query->asOf` is dropped by
 * the `scored` CTE's own `WHERE`, before a single score is computed for it, and never reaches PHP.
 * A row with no dates at all is kept but returned separately (`HybridSearchResult::$dateUnknown`),
 * never silently merged into `$confirmed` and never silently dropped — see `TemporalConfidence`'s
 * own docblock and `docs/reference/legislation-effective-dates.md` for why most current rows fall
 * in this bucket.
 *
 * That the date test comes first is a performance property, not a stylistic one, and it is the
 * difference between 14.5 seconds and 0.4 on the corpus as it stands. The scores in the select
 * list are a 384-dimension cosine distance, a trigram similarity and a ranked `tsvector` match,
 * all three evaluated per row that survives the `WHERE`. With the date test applied afterwards
 * they were evaluated for every row in the table instead.
 *
 * **Semantic ranking degrades gracefully when no embedding provider is configured** — `search()`
 * catches {@see EmbeddingProviderNotConfiguredException} from the query-embedding attempt and
 * proceeds lexical+fuzzy-only (`semantic_similarity` stays `NULL` for every row; the SQL's own
 * `CASE` already handles a `NULL` `query_embedding` parameter the same way). This is a narrowly
 * targeted catch of one named exception matching an established precedent in this codebase
 * (`Kern\Documents\Jobs\ProcessScannedDocument` does the same for `ExternalDocumentAiProvider`),
 * not a catch-all — `docs/CODE-QUALITY.md` §4's "no catch-all exception handlers" rule governs
 * swallowing an *unknown* error, not a documented, typed unavailability this class is explicitly
 * designed to degrade under.
 *
 * **`BLUEPRINT.md` §15.5 — no RAG calculations.** `combined_score` ranks retrieval relevance; it is
 * not, and must never be read as, a fiscal figure. Nothing in this class calls `TaxEngine` or
 * produces anything resembling a tax amount.
 */
final readonly class HybridLegislationSearch
{
    /** Ranking weights — retrieval relevance only, never a fiscal calculation (BLUEPRINT.md §15.5). */
    private const float WEIGHT_SEMANTIC = 0.5;

    private const float WEIGHT_LEXICAL = 0.3;

    private const float WEIGHT_CITATION = 0.2;

    /** pg_trgm's own conventional default (`pg_trgm.similarity_threshold`). */
    private const float CITATION_SIMILARITY_THRESHOLD = 0.3;

    private const float SEMANTIC_SIMILARITY_THRESHOLD = 0.3;

    public function __construct(
        private EmbeddingProvider $embeddingProvider,
    ) {
    }

    public function search(HybridSearchQuery $query): HybridSearchResult
    {
        $queryEmbeddingLiteral = $this->tryEmbedQuery($query->queryText);

        $filterSql = '';
        $filterBindings = [];

        if ($query->sourceCodes !== null) {
            $filterSql .= ' AND os.code = ANY(?::text[])';
            $filterBindings[] = $this->toPostgresTextArray($query->sourceCodes);
        }

        if ($query->trustTiers !== null) {
            $filterSql .= ' AND os.trust_tier = ANY(?::text[])';
            $filterBindings[] = $this->toPostgresTextArray(array_map(
                static fn (TrustTier $tier): string => $tier->value,
                $query->trustTiers,
            ));
        }

        if ($query->sectionType !== null) {
            $filterSql .= ' AND s.section_type = ?';
            $filterBindings[] = $query->sectionType->value;
        }

        $sql = <<<SQL
            WITH params AS (
                SELECT ?::text AS query_text, ?::date AS as_of, ?::vector AS query_embedding
            ),
            scored AS (
                SELECT
                    c.id AS chunk_id,
                    c.chunk_index,
                    c.chunk_count,
                    c.text,
                    s.id AS section_id,
                    s.section_type,
                    s.citation_label,
                    s.page_start,
                    s.page_end,
                    COALESCE(s.effective_from, dv.effective_from) AS effective_from,
                    COALESCE(s.effective_until, dv.effective_until) AS effective_until,
                    dv.id AS document_version_id,
                    sd.id AS source_document_id,
                    sd.issuer,
                    sd.document_type,
                    sd.publication_date,
                    os.code AS source_code,
                    os.trust_tier,
                    ts_rank_cd(c.text_search, plainto_tsquery('romanian', kern_unaccent(p.query_text)), 32) AS lexical_rank,
                    similarity(kern_unaccent(lower(s.citation_label)), kern_unaccent(lower(p.query_text))) AS citation_similarity,
                    CASE
                        WHEN c.embedding IS NOT NULL AND p.query_embedding IS NOT NULL
                        THEN 1 - (c.embedding <=> p.query_embedding)
                        ELSE NULL
                    END AS semantic_similarity,
                    -- Only two cases can reach here: the WHERE below has already dropped every
                    -- row whose known range excludes `as_of`, so a row either has no dates at all
                    -- or has dates that cover the date asked for. The range test is stated once,
                    -- there, rather than twice — once to label and once to filter.
                    CASE
                        WHEN COALESCE(s.effective_from, dv.effective_from) IS NULL THEN 'DATE_UNKNOWN'
                        ELSE 'CONFIRMED'
                    END AS temporal_status
                FROM ext_source_section_chunks c
                CROSS JOIN params p
                JOIN ext_source_sections s ON s.id = c.section_id
                JOIN ext_source_document_versions dv ON dv.id = s.document_version_id
                JOIN ext_source_documents sd ON sd.id = dv.source_document_id
                JOIN ext_official_sources os ON os.id = sd.official_source_id
                WHERE 1 = 1{$filterSql}
                  -- A date comparison, cheap, standing in front of three expensive scores: a
                  -- vector distance, a trigram similarity and a text rank, each of which this
                  -- CTE's select list computes per surviving row. Asked after the scoring — which
                  -- is where it used to be, in the outer query — every version of every law got
                  -- scored and then thrown away. On this corpus that is 583k rows scored to keep
                  -- 7k, because one law alone carries 91 consolidated versions and only the ones
                  -- in force on `as_of` can match. A row with no dates at all is kept, and is what
                  -- `DATE_UNKNOWN` above labels; dropping it here would be the search asserting a
                  -- non-match it cannot know.
                  AND (
                        COALESCE(s.effective_from, dv.effective_from) IS NULL
                        OR (
                              COALESCE(s.effective_from, dv.effective_from) <= p.as_of
                              AND (
                                    COALESCE(s.effective_until, dv.effective_until) IS NULL
                                    OR COALESCE(s.effective_until, dv.effective_until) >= p.as_of
                                  )
                           )
                      )
            )
            SELECT
                chunk_id, chunk_index, chunk_count, text,
                section_id, section_type, citation_label, page_start, page_end,
                effective_from, effective_until,
                document_version_id, source_document_id, issuer, document_type, publication_date,
                source_code, trust_tier,
                lexical_rank, citation_similarity, semantic_similarity, temporal_status,
                (COALESCE(semantic_similarity, 0) * ? + LEAST(lexical_rank, 1) * ? + citation_similarity * ?) AS combined_score
            FROM scored
            WHERE lexical_rank > 0
               OR citation_similarity > ?
               OR (semantic_similarity IS NOT NULL AND semantic_similarity > ?)
            ORDER BY combined_score DESC, chunk_id ASC
            LIMIT ?
            SQL;

        $bindings = [
            $query->queryText,
            $query->asOf->format('Y-m-d'),
            $queryEmbeddingLiteral,
            ...$filterBindings,
            self::WEIGHT_SEMANTIC,
            self::WEIGHT_LEXICAL,
            self::WEIGHT_CITATION,
            self::CITATION_SIMILARITY_THRESHOLD,
            self::SEMANTIC_SIMILARITY_THRESHOLD,
            $query->limit,
        ];

        /** @var list<stdClass> $rows */
        $rows = DB::select($sql, $bindings);

        $confirmed = [];
        $dateUnknown = [];

        foreach ($rows as $row) {
            $result = $this->hydrate($row);

            if ($result->temporalStatus === TemporalConfidence::CONFIRMED) {
                $confirmed[] = $result;
            } else {
                $dateUnknown[] = $result;
            }
        }

        return new HybridSearchResult(confirmed: $confirmed, dateUnknown: $dateUnknown);
    }

    private function tryEmbedQuery(string $text): ?string
    {
        try {
            // QUERY, not the PASSAGE default: this text is a person's question, and an
            // asymmetric model embeds the two sides differently. Passing the wrong one costs
            // retrieval accuracy without raising anything.
            $vectors = $this->embeddingProvider->embed([$text], EmbeddingPurpose::QUERY);
        } catch (EmbeddingProviderNotConfiguredException) {
            return null;
        }

        return $vectors[0]->toPgvectorLiteral();
    }

    /**
     * @param list<string> $values
     */
    private function toPostgresTextArray(array $values): string
    {
        $escaped = array_map(
            static fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"',
            $values,
        );

        return '{' . implode(',', $escaped) . '}';
    }

    private function hydrate(stdClass $row): RetrievalResult
    {
        return new RetrievalResult(
            chunkId: (string) $row->chunk_id,
            sectionId: (string) $row->section_id,
            documentVersionId: (string) $row->document_version_id,
            sourceDocumentId: (string) $row->source_document_id,
            sourceCode: (string) $row->source_code,
            trustTier: TrustTier::from((string) $row->trust_tier),
            issuer: $row->issuer !== null ? (string) $row->issuer : null,
            documentType: (string) $row->document_type,
            sectionType: SectionType::from((string) $row->section_type),
            citationLabel: (string) $row->citation_label,
            effectiveFrom: $this->parseDate($row->effective_from),
            effectiveUntil: $this->parseDate($row->effective_until),
            publicationDate: $this->parseDate($row->publication_date),
            pageStart: $row->page_start !== null ? (int) $row->page_start : null,
            pageEnd: $row->page_end !== null ? (int) $row->page_end : null,
            chunkIndex: (int) $row->chunk_index,
            chunkCount: (int) $row->chunk_count,
            text: (string) $row->text,
            temporalStatus: TemporalConfidence::from((string) $row->temporal_status),
            lexicalRank: (float) $row->lexical_rank,
            semanticSimilarity: $row->semantic_similarity !== null ? (float) $row->semantic_similarity : null,
            citationSimilarity: (float) $row->citation_similarity,
            combinedScore: (float) $row->combined_score,
        );
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('Expected a date column to be returned as a string.');
        }

        return new DateTimeImmutable($value);
    }
}
