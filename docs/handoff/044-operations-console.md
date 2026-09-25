# 044 — Operations console (`/admin/operatiuni`)

The last placeholder in the admin navigation is now a real screen. It answers "what is this
installation doing in the background, and is it working", and lets an administrator act on the
answer.

See `docs/adr/0015-database-driven-schedule-with-code-declared-tasks.md` for why the schedule is
split across three sources instead of living entirely in code or entirely in the database.

## What it shows

| Panel | Source | Notes |
|---|---|---|
| Scheduler | heartbeat timestamp in the cache | See "The heartbeat crosses containers" below |
| Queue | Horizon's `JobRepository` / `WorkloadRepository` / `MasterSupervisorRepository` | Counts are `null`, never `0`, when Horizon is unreachable |
| Scheduled tasks | `OperationTask` + `config/operations.php` + `scheduled_tasks` | Pause switch, "Run now", last outcome |
| Data freshness | `fx_rates`, `source_documents`, `source_sections`, `source_section_chunks`, `documents`, pending migrations | The question no queue metric answers |
| Services | `ProbeSystemHealth` | Reused verbatim from the settings screen |
| Failed jobs | `queue.failed` provider | Retry / discard |
| Official sources | `config/sources.php` (+ one query for "mirrored") | Read-only — see below |
| Run history | `job_runs` | Scheduled and manual runs in one ledger |

## New module: `packages/tax-romania/src/Operations`

```
Enums/         OperationTask, JobRunStatus, JobRunTrigger
Models/        JobRun, ScheduledTask
DataTransferObjects/  ScheduledTaskDefinition, TaskArgument, SchedulerStatus,
                      QueueSnapshot, FreshnessItem, FailedJobSummary, SourceRegistryEntry
Services/      ScheduledTaskRegistry, JobRunRecorder, OperationRunLock,
               ProbeSchedulerStatus, ProbeQueueStatus, ProbeDataFreshness
Repositories/  JobRunRepository, FailedJobRepository, SourceRegistryRepository
Jobs/          RunOperationTask
```

Deptrac layer `Operations`, granted `Support`, `Audit` and `Documents`. The `Documents` grant covers
exactly one symbol — `DocumentStatus`, a backed enum read to count documents left in a non-terminal
state. Every other table is read through the plain query builder, the same crossing
`TransactionMatchCandidateFinder` already establishes for `financial_transactions`.

## Endpoints

```
GET    /api/v1/operations                          operations.view
GET    /api/v1/operations/runs                     operations.view
GET    /api/v1/operations/failed-jobs              operations.view
POST   /api/v1/operations/tasks/{task}/run         operations.run   -> 202 | 409 | 404 | 422
PUT    /api/v1/operations/tasks/{task}             operations.run   -> {enabled} only
POST   /api/v1/operations/failed-jobs/{uuid}/retry operations.run
DELETE /api/v1/operations/failed-jobs/{uuid}       operations.run
PUT    /api/v1/operations/sources/{code}           operations.run
```

Every write is recorded in the audit chain: `OPERATION_TASK_RUN_REQUESTED`,
`SCHEDULED_TASK_TOGGLED`, `FAILED_JOB_RETRIED`, `FAILED_JOB_FORGOTTEN`.

## Key decisions

1. **The task list is a PHP enum, not a table.** A row naming an artisan command to execute is
   remote code execution behind an administration form. There is no schedule editor and no
   cron-expression field for the same class of reason.
2. **Task arguments are bounded integers only.** `TaskArgument` has no string case, and the
   controller builds its validation rules from the task's own declaration rather than restating
   them, so the form and the server cannot drift.
3. **The run lock is taken in the HTTP request, not in the job.** A double-click gets a 409 with an
   answer instead of two concurrent imports racing on the same `source_document_versions` rows —
   which is a provenance defect, not merely wasted work.
4. **A failed job's payload is never sent to the browser.** It carries document and tax subject ids
   and, through `SerializesModels`, whatever re-hydrating those models would surface. The class name
   and the exception's first line are what the screen shows.
5. **Unreachable Horizon yields `null`, not `0`.** A zero reads as "nothing is queued" during
   exactly the incident where the truth is that nobody can tell.
6. **The scheduler beats twice**, to two audiences that must fail independently — see below.

## The official-source allowlist is read-only, on purpose

The first version of this panel read `official_sources` and offered a switch over its `enabled`
column, described as "the kill switch for one upstream". That was wrong twice, and the panel showed
an empty table after a restore, which is how it was caught:

1. **`official_sources` is a mirror, not the authority.** `config/sources.php` declares the
   allowlist, and `OfficialSourceRepository::resolve()` upserts `trust_tier`/`base_url`/`enabled`
   from that config on *every* call — so a value written by the screen survived only until the next
   import resolved the source.
2. **Nothing reads that column.** `AllowlistedHttpFetcher` checks the config-derived
   `SourceDefinition`. The switch would have shown a source as disabled while every import kept
   running against it.

The table is also filled lazily, on first fetch, so an empty `official_sources` means "nothing has
been imported yet", not "nothing is allowlisted" — which is exactly what it meant after the database
wipe. `ReadSourceAllowlist` now lists all four declared entries from config and adds a `mirrored`
flag so the two situations are distinguishable on screen.

Presented read-only for the same reason `SettingKey::isReadOnly()` refuses the KMS driver: a value
the server cannot honour must not be offered as editable. Making it a real kill switch is possible
but is a change of *authority* — `resolve()` would have to stop overwriting `enabled`, and the
fetcher would have to consult the row — which inverts "config is always authoritative" on the
provenance path. That is a deliberate design decision, not a UI fix.

## The heartbeat crosses containers

The first version of this screen reported a perfectly healthy scheduler as **Stalled / No
heartbeat**, and the reason is worth recording because it is not visible from either side alone:
`app` and `scheduler` each get their **own** `tmpfs` at `/tmp` (`compose.yaml`'s shared php
service definition). The scheduler's `/tmp/scheduler-heartbeat` is real and fresh; the `app`
container that renders this screen cannot see it at all. `app_storage` is no help either — it is
mounted in both, but root-owned, so the runtime user cannot write to it.

The `scheduler-heartbeat` entry therefore writes twice, once per audience:

| Written to | Read by | Why local / why shared |
|---|---|---|
| `OPERATIONS_HEARTBEAT_PATH` (file) | `docker/php/scheduler-healthcheck.php`, same container | Local on purpose: a healthcheck that went red because Redis was down would restart a scheduler that is working perfectly |
| `OPERATIONS_HEARTBEAT_CACHE_KEY` (Redis) | `ProbeSchedulerStatus` in `app` | The only channel both containers already share |

A timestamp is stored rather than a self-expiring marker, so the screen can report *how long* a
stall has lasted rather than only that one exists. `ProbeSchedulerStatus` prefers the cache and
falls back to the file, which keeps it correct when invoked from the scheduler container itself.

If Redis is unreachable this reports "stalled" when the scheduler may be fine. That is not a
practical false alarm — sessions live in Redis too, so nobody can be signed in to see the screen
while it is down — and the alternative, treating an unreadable heartbeat as healthy, is the failure
mode this module exists to eliminate.

`tests/Feature/TaxRomania/Operations/SchedulerHeartbeatTest.php` covers all of it, including running
the real registered schedule entry and asserting that both writes happen.

## New command

`kern:audit:verify [--chain=]` — walks every audit chain and recomputes every hash, exiting 1 on
the first failure. The API's summary endpoint bounds itself to 25 chains so a page load stays fast;
this verifies all of them, which is why it belongs on a queue and in the run ledger.

## Applying it

```
make check                     # green
docker compose exec app php artisan migrate
docker compose exec app php artisan kern:access:sync    # creates operations.view / operations.run
docker compose up -d --force-recreate app queue scheduler   # new env vars in compose.yaml
```

The recreate is for the new `OPERATIONS_*` variables in `compose.yaml`, not for the PHP: since
`docker/php/php.dev.ini` was added, development picks up edited PHP on the next request. Compose
still only reads a container's environment when it creates it.

`kern:access:sync` is required — without it the new permissions exist in `config/access.php` but
not in the database, and every operations route answers 403.

## What is deliberately not here

- **`kern:legislation:ingest`.** It takes a source code, a URL and a document type — an ingestion
  decision with a provenance consequence, not a button. `OperationTaskTest` asserts its absence.
- **Retiming a job from the UI.** Timing stays in `config/operations.php` / env.
- **KMS key version and rotation state.** Additive later; it changes no shape on this page.

## Known-unrelated failures

`make check-php-pg` reports 10 pre-existing failures in `Feature/Domain/{Documents,Identity}` —
cross-test leakage of committed rows encrypted under a previous run's random `InMemoryKeyManagement
Service` key. Verified unrelated by running the same suite with this task's tests removed: the same
10 fail. `make check` (sqlite) is fully green.
