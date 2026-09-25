# ADR 0015 — A database-driven schedule whose tasks are declared in code

**Status:** accepted
**Date:** 2026-09-09
**Supersedes:** nothing. Extends the schedule introduced in `bootstrap/app.php` at Milestone 4.

## Context

`/admin/operatiuni` needs to answer three operational questions and act on two of them:

1. Is the nightly BNR import running, and did it work?
2. Can an administrator pause it without a deploy?
3. Can an administrator start it now?

Laravel has no database-backed scheduler. The schedule is a closure — code — evaluated by
`schedule:run`. Nothing in the framework stores schedule entries, and nothing records the outcome
of an artisan command: Horizon knows a *job* ran, not that an import found zero new rates.

Three shapes were considered.

**A. Everything in code.** The schedule stays as it was; the screen is read-only. Honest, but
answers question 2 with "redeploy", and this image runs `opcache.validate_timestamps=Off`, so even
a one-line schedule change needs `docker compose up -d --force-recreate app`.

**B. Everything in the database.** A `scheduled_tasks` table with a `command` column and a
`cron_expression` column, both editable from the screen. This is what most "cron UI" packages do.
It is also remote code execution behind an administration form — `kern:fx:import-daily` and
`migrate:fresh` are both strings, and nothing downstream can tell them apart — and a free-text cron
field is the standard way a job stops running forever with no error raised anywhere.

**C. Split by question.** Adopted.

## Decision

Three sources, one per question, each in the place that can answer it safely:

| Question | Source | Mutable at runtime |
|---|---|---|
| *Which* tasks exist | `Kern\TaxRomania\Operations\Enums\OperationTask` (closed PHP enum) | no |
| *When* they run | `config/operations.php`, env-overridable | no (deploy or container env) |
| *Whether* they run | `scheduled_tasks.enabled` | **yes** |

`bootstrap/app.php` reads `ScheduledTaskRegistry::schedulable()` on every tick. The `scheduler`
container runs `schedule:work`, which spawns a fresh `schedule:run` subprocess each minute, so the
closure re-evaluates and a toggle takes effect within a minute with no restart — verified by
pausing a task in the database and watching it leave `schedule:list` on the next tick.

Two supporting decisions follow from this shape:

**Scheduled entries dispatch `RunOperationTask` rather than `$schedule->command(...)`.** A scheduled
run and an operator's "Run now" then travel the identical path: the same lock, the same `job_runs`
ledger row, the same recorded exit code. A ledger covering only manual runs could not answer "did
last night's import work", which is the question the whole screen exists for.

**`job_runs` records a row before the command executes, not after.** A task that never returns — an
OOM-killed worker, a container restart mid-import — still leaves evidence that it started. A row
stuck in `RUNNING` is itself the diagnostic.

## Consequences

- Adding a runnable task is a code change and a code review, exactly like adding a route. That is
  the intended cost.
- The registry must never throw. If reading the pause flags failed, `schedule:run` would abort and
  nothing would run that minute — including the heartbeat that reports the scheduler healthy. It
  degrades to the code-declared defaults and reports the failure instead.
- Retiming a job still requires an env change and a recreate. Accepted: a cron expression typed
  into a web form is not a capability worth the failure mode it introduces.
- `kern:legislation:ingest` is absent from the enum. It takes a source code, a URL and a document
  type — an ingestion decision with a provenance consequence (`BLUEPRINT.md` §7.1), not a button.
  `tests/Feature/TaxRomania/Operations/OperationTaskTest.php` asserts its absence, so adding it later is
  a deliberate act with a failing test to answer for.
- The official-source allowlist is rendered read-only. It is declared in `config/sources.php` and
  re-mirrored onto `official_sources` at every fetch, and `AllowlistedHttpFetcher` checks the config
  definition rather than the row — so a switch over that column would report a state it cannot
  enforce. See `docs/handoff/044-operations-console.md`.
- Two new permissions, `operations.view` and `operations.run`, deliberately distinct from
  `system.settings.manage`: "restart the importer" and "change where taxpayer documents are sent for
  AI processing" are different levels of trust, and one permission covering both would grant the
  second to everyone who needs the first.
