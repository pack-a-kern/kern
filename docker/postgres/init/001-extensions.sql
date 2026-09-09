-- TaxOS România — Postgres bootstrap extensions.
-- Runs once, automatically, via /docker-entrypoint-initdb.d/ on first init
-- of an empty PGDATA volume (the official postgres image's own convention —
-- it will NOT re-run against an existing volume).
--
-- BLUEPRINT.md §6.10 / docs/reference/verified-versions.md: vector (pgvector),
-- unaccent (Romanian diacritics ș/ț/ă/î/â) and pg_trgm (fuzzy article
-- lookup) are required. The built-in `romanian` text-search configuration
-- needs no extension — confirmed present on pgvector/pgvector:pg18-trixie
-- by a real `SELECT cfgname FROM pg_ts_config WHERE cfgname = 'romanian'`
-- (see docs/handoff/001-docker-topology.md "How to verify").

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
