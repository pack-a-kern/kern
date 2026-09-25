-- A dedicated database for the test suite.
--
-- Runs once, via /docker-entrypoint-initdb.d/, alongside 001-extensions.sql.
--
-- The suite runs `migrate:fresh`, which drops every table. Before this existed, the only Postgres
-- database in the project was the development one, so the Postgres-only suites (the audit chain's
-- `SELECT … FOR UPDATE`, `ilike`, `count(*) FILTER`) had nowhere to run except on top of real
-- development data — and one `pest` invocation without the sqlite flags destroyed users, settings
-- and an ingested legislation corpus. `Tests\TestCase` now refuses any database not on its
-- allowlist; this creates the one it allows.
--
-- Extensions are per-database in Postgres, so they must be created again here rather than
-- inherited from the development database.
CREATE DATABASE kern_test OWNER kern;

\connect kern_test

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
