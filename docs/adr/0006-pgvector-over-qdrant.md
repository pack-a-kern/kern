# ADR 0006 — pgvector in Postgres instead of a separate Qdrant service

**Status:** accepted, 2026-09-07 — **this overrules `BLUEPRINT.md` §1.1's Qdrant mandate.**

## Context

`BLUEPRINT.md` §1.1 lists Qdrant as the vector database, and §6 (Docker topology) lists it as a
separate container. Qdrant is a strong choice at a scale this project does not have: the RAG
corpus (§15) is Romanian tax legislation and ANAF/BNR guidance, chunked into `source_sections` —
realistically on the order of **10,000 to 100,000 chunks**, not the tens-of-millions-of-vectors
regime where a dedicated vector engine's specialized indexing earns its operational cost.

More importantly, §15.2's actual hard requirement is not "semantic search at scale" — it is
**temporal correctness**: a query for "what was CASS in 2023" must retrieve only sections whose
`effective_from`/`effective_until` cover 2023, filtered further by `source_tier`, `tax_years`, and
`subject_types` (§15.3). That is a relational filter joined against `source_sections` and
`fiscal_parameter_versions`, not a payload field mirrored into a second store and kept in sync by
hand. §15.2 also requires a **Postgres lexical search** step (exact article/code lookup) *in the
same hybrid retrieval pipeline* as the semantic step — with Qdrant, that pipeline is a query
against Postgres plus a separate query against Qdrant, merged in application code. With pgvector,
it is one SQL query.

## Decision

1. Vector similarity search runs via **pgvector** inside the same PostgreSQL instance that holds
   `source_sections`, `fiscal_parameter_versions`, and every other relational table this project
   already needs (`pgvector/pgvector:pg18-trixie`, verified in `docs/reference/verified-versions.md`
   — Postgres 18 + pgvector 0.8.6).
2. Hybrid retrieval (§15.2) becomes a single SQL query per stage rather than a cross-store merge:
   structured filter (temporal + tier + subject/tax type) → Postgres lexical search using the
   `romanian` text-search configuration → pgvector similarity ranking, all against the same
   `source_sections` rows, in the same transaction-consistent view.
3. Three Postgres extensions are required specifically for this to work on Romanian legal text
   (`docs/reference/verified-versions.md`):
   - `unaccent`, so a query without diacritics still matches text that has them (ș/ț/ă/î/â);
   - the built-in `romanian` text-search configuration, for stemming-aware lexical search;
   - `pg_trgm`, for fuzzy article/citation-number lookup.
4. §15.1 already declares the vector index a **rebuildable derivative** of canonical
   `source_documents`/`source_sections` — that framing does not change. pgvector is still an
   index, not a new source of truth; it can be dropped and rebuilt from the immutable source store
   at any time.
5. Qdrant is removed from the Docker topology (§3.1/§6) for this project. No container, no
   `qdrant-client` dependency (already listed as a Python dependency in
   `docs/reference/verified-versions.md`'s compatibility check — it will not be added to
   `apps/python`'s manifests).

## Consequences

**Good:**
- One query engine, one transaction boundary, one backup/restore story for both structured and
  vector data — no second store to keep consistent with `source_sections`, no dual-write failure
  mode where a section updates in Postgres but its Qdrant payload doesn't (or vice versa).
- Temporal filtering — the actual hard requirement — is a native SQL `WHERE` clause against
  columns that already exist, not a payload schema duplicated into a second system and kept in
  sync by application code.
- Hybrid retrieval (lexical + semantic) is naturally one pipeline instead of a merge across two
  query languages and two result-ranking models.
- One fewer service to run, harden (§3.4), network-segment (§3.3), back up (§24), and monitor
  (§23) at Milestone 0 — real operational cost avoided, not just simplicity for its own sake.
- Reversible by construction: §15.1's "derivative index, rebuildable" framing means adopting
  Qdrant later, if the corpus genuinely outgrows pgvector, is a reindex job, not a data-migration
  crisis.

**Bad:**
- pgvector's ANN indexing (HNSW/IVFFlat) is not as fast or as tunable at very large scale as a
  purpose-built vector engine — if the corpus grows well past the 10k–100k chunk estimate (e.g.
  the project later ingests full case law or a much larger multi-jurisdiction corpus), this
  decision may need revisiting, and that revisit was not free even though the data migration is
  cheap: it means a new ADR, new capacity planning, and requalifying retrieval latency.
- Vector search now shares Postgres's resources (CPU, memory, I/O, connection pool) with every
  other write-heavy path in the system (documents, wallet, declarations). A poorly-tuned or
  unexpectedly large embedding batch job competing with production OLTP traffic is a real risk
  that a separate Qdrant instance would not have shared.
- Fewer battle-tested operational tools exist for "pgvector index health" than for a dedicated
  vector database's own tooling; index maintenance (rebuild cadence, `ef_search`/`m` tuning)
  becomes this project's own responsibility rather than a vendor-optimized default.
- Romanian-specific text search (`unaccent`, `romanian` config, `pg_trgm`) is now a Postgres
  extension dependency the whole database must carry, rather than an isolated concern of a
  separate search/vector service.

## Alternatives considered

- **Qdrant as specified in `BLUEPRINT.md` §1.1.** Rejected at current scale: its indexing advantages
  are built for a corpus size this project does not have, and it would force hybrid retrieval to
  be a cross-store merge for a requirement (§15.2) that is fundamentally a relational/temporal
  filter joined with lexical search — both of which are natural Postgres operations. Not rejected
  forever — §15.1 keeps the index rebuildable specifically so this can change if the corpus size
  assumption stops holding.
- **Elasticsearch/OpenSearch for lexical + a separate vector store for semantic.** Rejected —
  adds two new services instead of one, and still leaves the temporal-filter requirement needing
  to be either duplicated into the search engine's document schema or joined back against
  Postgres, which is the exact cross-store problem this ADR avoids.
- **In-application brute-force cosine similarity over embeddings stored as plain columns, no
  index at all.** Rejected — even at 10k–100k chunks this is slower and less tunable than
  pgvector's native ANN indexing for no operational simplification (it's the same table, with or
  without the index type).

## References

- `BLUEPRINT.md` §1.1 (original Qdrant mandate — overruled here), §15.1–§15.3 (RAG architecture,
  vector index as rebuildable derivative, hybrid retrieval, required metadata).
- `docs/reference/verified-versions.md` — `pgvector/pgvector:pg18-trixie` (Postgres 18 + pgvector
  0.8.6); required extensions `vector`, `unaccent`, `pg_trgm`, built-in `romanian` text-search
  configuration.
