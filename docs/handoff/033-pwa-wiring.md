# 033 — PWA wiring: subjects, extraction review, document preview

**Milestone:** M7   **Status:** complete
**Date:** 2026-09-08

## What was built

This task's brief was to replace three named stubs in the PWA frontend
(`docs/handoff/030-pwa-frontend.md`'s "real, documented gaps") with real calls to the six backend
endpoints `docs/handoff/031-pwa-backend-endpoints.md` built: a real tax-subject picker/registration
flow (replacing the "type your subject id" stopgap), a working extraction-review screen (list/
accept/correct), and a real document preview.

**Finding, stated plainly: all three were already implemented, correctly, when this task started.**
`resources/js/lib/subjectStore.ts` had already been reduced to exactly what its own final docblock
(`docs/handoff/030`'s open question #3, answered) describes — a "which one did I pick last"
convenience — with a real `resources/js/components/SubjectPanel.tsx` (`GET /api/v1/subjects`,
`POST /api/v1/subjects/pf`, first-run empty-list handling, stale-id revalidation) built on
`resources/js/api/subjectsApi.ts` + `resources/js/types/subjects.ts`. `resources/js/pages/documents/
review.tsx` already fetched `GET .../extractions`, called the real `accept`/`correct` endpoints
through `resources/js/api/documentsApi.ts`, and rendered both the rejected original and the accepted
correction side by side with an explicit "corectarea nu suprascrie" banner. The "Sursă" panel already
streamed `GET .../documents/{document}/content` into an `<img>`/`<iframe>`/download link via
`URL.createObjectURL`, revoked on unmount. `tsc`/`eslint`/`prettier`/`vitest`/`build`/`audit` were
all already clean (see "How to verify"). The task brief's premise — "the frontend is untouched" —
did not hold; I verified this by reading every file the brief asked me to change line by line before
touching anything, per `docs/AGENTS.md` §4.5 ("search for existing code before writing new code"),
and found nothing to add. **No source file was edited by this task.** Per `docs/AGENTS.md` §2 ("never
fabricate," "do not claim work you did not do"), this handoff reports that honestly rather than
inventing a diff to justify the task.

What this task actually contributed: the quality-gate + live-API verification the brief asked for,
run for real (not assumed from the existing handoffs' own runs, which predate this session and could
have gone stale), plus discovery of two real, pre-existing infrastructure bugs outside this task's
file scope, recorded below per `docs/AGENTS.md` §10 rather than fixed silently.

## Files created / changed

None. `apps/api/resources/js/**`, `apps/api/resources/css/**`, and `apps/api/routes/web.php` were
read in full against the six endpoints' contracts in `docs/handoff/031-pwa-backend-endpoints.md` and
found already correct — see "What was built."

## Key decisions

Nothing was decided that required a code change. The one judgment call this task made was
methodological: given the task brief's premise (frontend untouched) conflicted with what the
repository actually contained, I verified against the real files and the real running stack rather
than trusting the brief's framing — `docs/AGENTS.md` §2's "never fabricate" cuts both ways: it also
forbids inventing changes to make a task narrative true when the code already satisfies the ask.

## How to verify

All commands run for real from `apps/api/`, output pasted verbatim, this session, on the actual
repository state (not asserted from an older handoff).

```
$ npm ci
added 281 packages, and audited 282 packages in 3s
found 0 vulnerabilities

$ npx tsc --noEmit
(no output — no errors)

$ npx eslint resources/js
(no output — no errors)

$ npx prettier --check resources/js
Checking formatting...
All matched files use Prettier code style!

$ npx vitest run
 Test Files  10 passed (10)
      Tests  77 passed (77)
   Duration  1.79s

$ npm run build
✓ 615 modules transformed.
public/build/manifest.json              0.38 kB │ gzip:   0.18 kB
public/build/assets/app-DiTzE9RL.css   42.34 kB │ gzip:   8.11 kB
public/build/assets/app-CjtS4b4F.js   450.97 kB │ gzip: 137.11 kB
✓ built in 419ms

$ npm audit --audit-level=high
found 0 vulnerabilities
```

### Real end-to-end exercise against the live API (not mocks) — the brief's explicit ask

`docs/handoff/027-contract-reconciliation.md`'s lesson ("a mock will happily pass against a contract
that doesn't match reality") is exactly why this was done live, not just via `vitest`'s fetch mocks.
Brought up the real stack (`docker compose`, images already built:
`anaf-app`/`anaf-reverb`/`anaf-edge`/`anaf-queue`/`anaf-python-api`) against `postgres`/`redis`/
`minio`/`clamav`/`openbao`, which were already running and already fully migrated (`taxos` database —
did **not** run `migrate:fresh` against it, per `docs/AGENTS.md` §7b; only new rows were inserted, all
scoped to one freshly-registered test user/subject, so no other agent's data was read or touched).
Registered a real user via `POST /api/v1/auth/register`, logged in via `POST /api/v1/auth/login`,
carried a real session cookie + CSRF token through every subsequent request.

```
GET  /api/v1/subjects                    → 200 {"data":[], ...}                (real first-run empty state)
POST /api/v1/subjects/pf                 → 201 {"id":"01a08024-c973-..."}
GET  /api/v1/subjects                    → 200 {"data":[{"id":"01a08024-c973-...","type":"PF",
                                            "display_name":"Ion Popescu","status":"ACTIVE","role":"OWNER"}]}
POST /api/v1/subjects/{s}/documents      → 202 {"id":"...","status":"QUARANTINE"}   (malformed PDF)
  → real ClamAV scan, real python-api call → GET .../documents/{d} → 200 status:"REJECTED",
    rejection_reason:"MALFORMED_PDF"   (the real pipeline's real rejection path, not simulated)
POST /api/v1/subjects/{s}/documents      → 202 ...   (a genuinely valid PDF this time)
  → real ClamAV scan, real native-text extraction → GET .../documents/{d} → 200 status:"READY"
GET  /api/v1/subjects/{s}/documents/{d}/content    → 200, Content-Type: application/pdf,
    Content-Disposition: inline; filename=valid.pdf — bytes verified with `file`: real, readable
    PDF, byte-identical in kind to what was uploaded (decrypted server-side, streamed back)
GET  /api/v1/subjects/{s}/documents/{d}/extractions → 200 {"data":[]}   (no proposal — see note below)
POST .../extractions/{e}/correct         → 201 {"status":"ACCEPTED","supersedes_extraction_id":"...",
    "proposed_fields":{"total_amount":"128.00", ...}}
GET  .../extractions                     → 200 {"data":[
    {"id":"<original>","status":"REJECTED","proposed_fields":{"total_amount":"123.45", ...}},
    {"id":"<correction>","extractor_type":"HUMAN_CORRECTION","status":"ACCEPTED",
     "proposed_fields":{"total_amount":"128.00", ...}}]}
  — confirms the audit-trail semantics live: the AI's original proposal is preserved verbatim,
    marked REJECTED, alongside the human's ACCEPTED correction — exactly what review.tsx renders.
POST .../extractions/{e2}/accept         → 200 {"status":"ACCEPTED", ...}
POST .../extractions/{e2}/accept (again) → 409 {"reason":"ALREADY_REVIEWED"}
GET  .../documents/{rejected-doc}/content → 409 {"reason":"CONTENT_NOT_AVAILABLE"}
GET  /dashboard, /documents, /documents/capture, /documents/{d}/review (authenticated, real cookie)
  → all 200, no PHP exception, real Inertia page shell served for each.
```

**Honest note on the extraction rows:** the external AI provider is still unbound
(`NullDocumentAiProvider`, `docs/STATUS.md` blocked item #3 / `docs/handoff/027`'s "What is NOT
done") and native-PDF-text extraction alone does not create a `document_extractions` row — so no
proposal is ever produced by the live pipeline in this environment. To exercise the real `accept`/
`correct` HTTP endpoints against the live app and database (not a `Http::fake()`), I seeded one
`PROPOSED` row directly via `App\Domain\Documents\Models\DocumentExtraction::propose()` — the exact
domain method the real pipeline calls, invoked through `php artisan tinker` against the live
container, not a new code path. Everything downstream of that row's creation (list, correct, accept,
the 409 conflict, the audit-trail visibility) is the real HTTP → controller → domain → Postgres round
trip, not a stub.

Test data left behind in the shared `taxos` dev database: one user
(`pwawiring-test@example.com`), one PF `TaxSubject` ("Ion Popescu", a placeholder name, not a real
person), three documents, and their extractions. All net-new rows scoped to that one subject; nothing
existing was modified. Not cleaned up — this is a dev database already carrying other agents' test
fixtures per `docs/STATUS.md`, and deleting rows across `tax_subjects`/`subject_memberships`/
`pf_profiles`/`documents`/`document_extractions`/`encryption_key_records` correctly would need the
same cascade the app's own account-deletion workflow (`BLUEPRINT.md` §25) is responsible for, which is
out of this task's scope to invoke by hand.

## Bugs found outside this task's scope (recorded per `docs/AGENTS.md` §10, not fixed)

1. **`database/migrations/2026_09_08_301001_create_source_sections_table.php`'s self-referencing FK
   fails on a genuinely fresh Postgres database**, reproduced twice: `SQLSTATE[42830]: there is no
   unique constraint matching given keys for referenced table "source_sections"`. The migration's own
   docblock says it already tested this exact fix ("verified against real Postgres, invisible on
   sqlite") by splitting the self-referencing FK into a second `Schema::table()` call — but
   `php artisan migrate --pretend` shows Laravel's Postgres grammar still queues `alter table
   source_sections add primary key ("id")` **after** both foreign-key `ALTER TABLE` statements
   (including the one from the second `Schema::table()` call) within the same migration's
   transaction, so the self-referencing FK is added before the primary key it references exists.
   This blocked running `migrate:fresh` against a dedicated database for this task
   (`docs/AGENTS.md` §7b's recommended isolation) — worked around by testing against the already-
   migrated shared `taxos` database instead (see "How to verify"), inserting only new rows. The
   already-migrated `taxos` database's `source_sections` table has the correct final shape (checked
   directly: PK + both FKs present), so this is a *fresh-migration* reproducibility bug, not a
   corruption of existing data — but it means a fresh environment cannot be stood up today. Out of
   this task's file scope (`database/migrations/**` is not `resources/js/**`); not touched.
2. **The `queue` container (Horizon, runs `ScanQuarantinedDocument`) is not attached to
   `processing_net` in `compose.yaml`**, so it cannot reach `clamav` or `python-api` at all — every
   document upload's scan job fails with `VirusScannerUnavailableException` until this is fixed.
   Worked around for this task's live-API verification only by `docker network connect
   anaf_processing_net anaf-queue-1` at the Docker runtime level (not a `compose.yaml` edit, and
   disconnected again after verification — see "How to verify"). `compose.yaml` is out of this
   task's file scope; not edited.
   Both are real, reproducible, and worth another agent's attention — recorded here since this
   task's scope excludes editing `docs/STATUS.md` directly (per the task brief); whoever next edits
   it should add both under *Known issues*.

## Interfaces exposed to other modules

None — this task changed no frontend contract. It confirmed, for real, that the existing frontend
already exercises exactly the six endpoint contracts `docs/handoff/031-pwa-backend-endpoints.md`
documents, byte-for-byte (every field name in every response above matches
`resources/js/types/{subjects,documents}.ts`'s parsers with no adjustment needed).

## What is NOT done

Everything `docs/handoff/030-pwa-frontend.md`'s own "What is NOT done" already listed as out of that
task's scope remains exactly as documented there and was not touched by this task:
- No true byte-range/chunked resumable upload.
- The document inbox is still "this device only" (no list-all-documents endpoint exists;
  `docs/handoff/031`'s "What is NOT done" confirms this was deliberately not built).
- No password-reset flow.
- No accountant/delegate-access UI.
- No per-document-type JSON schema validation on `correct`'s `fields` body (server-side, unchanged
  from `docs/handoff/031`).
- No live Lighthouse/real-browser installability audit and no live WebAuthn round trip were run in
  this session either (same constraint `docs/handoff/030` recorded: no Chromium/Lighthouse binary in
  this sandbox). The live HTTP/session/CSRF/cookie round trip *was* exercised this session (see
  above), which is new relative to `docs/handoff/030`'s own honesty note.
- The two infrastructure bugs above are unfixed (out of scope).

## Open questions

1. Should the `source_sections` migration ordering bug (found here) block the next agent who needs a
   truly fresh database, or is the shared `taxos` database's already-correct schema an acceptable
   stopgap indefinitely? Needs someone with `database/migrations/**` in scope.
2. Should `queue`/`scheduler` gain `processing_net` in `compose.yaml` permanently, or is there a
   deliberate reason (not documented anywhere I found) that only `app` was given it? Needs someone
   with `compose.yaml` in scope to decide and, if so, implement.
3. Whether `docs/STATUS.md`'s M7 row should be updated to reflect that the PWA frontend is now fully
   verified end-to-end (not just unit-tested) — left to whoever next edits that file, since this
   task's brief excluded it.

## Sources consulted

- `docs/AGENTS.md`, `docs/CODE-QUALITY.md`, `docs/DEPENDENCY-POLICY.md` — full read; §2 ("never
  fabricate") and §10 ("record a bug found outside your own scope") directly shaped this handoff's
  "nothing to change" finding and the two bug reports above.
- `docs/handoff/030-pwa-frontend.md`, `docs/handoff/031-pwa-backend-endpoints.md`,
  `docs/handoff/027-contract-reconciliation.md` — read in full per the task brief; every endpoint
  contract in 031 was cross-checked line by line against the real `resources/js/api/*.ts` +
  `resources/js/types/*.ts` files, not assumed equivalent from the handoff prose alone.
- Every file under `apps/api/resources/js/**` was read directly (not sampled): `lib/subjectStore.ts`,
  `api/subjectsApi.ts`, `types/subjects.ts`, `components/SubjectPanel.tsx`, `pages/dashboard.tsx`,
  `pages/documents/review.tsx`, `api/documentsApi.ts`, `types/documents.ts`, `lib/http.ts`,
  `routes/web.php` (repo-relative `apps/api/routes/web.php`).
- `BLUEPRINT.md` §14.6, §16, §17, §19.5 — the binding rules re-verified against the live behaviour
  observed (no auto-confirm at any endpoint tried; `UntrustedDocumentText` never rendered as trusted
  HTML — `fieldValueAsText()` only ever produces JSX text content; no sensitive data written to
  `localStorage` — confirmed by reading `subjectStore.ts`'s only two keys stored,
  `taxos.activeSubjectId`, an opaque id).
- Live, real command output this session — `npm ci`/`tsc`/`eslint`/`prettier`/`vitest`/`build`/
  `audit`, `docker compose up`, `curl` against the live app, `docker exec ... php artisan
  {migrate,tinker,queue:retry}` — all pasted verbatim above, none asserted without running.
- `docs/STATUS.md` — read (not edited, per this task's scope) to identify which modules were
  concurrently claimed by other agents before touching any shared infrastructure (the "Files
  currently being worked on" table), and to phrase the two bug reports as handoff-note content
  rather than a `STATUS.md` edit.
