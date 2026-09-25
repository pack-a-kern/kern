# AGENTS.md — binding rules for every agent working in this repository

**Status:** normative. Read this file completely before your first tool call.
**Authority:** `BLUEPRINT.md` (the blueprint) is the source of truth. This file operationalises it.
Where this file and `BLUEPRINT.md` conflict, `BLUEPRINT.md` wins and you must report the conflict.

---

## 0. The one rule everything else serves

> **AI can read, classify, extract and explain. AI never decides a tax number.**
> Tax results come exclusively from deterministic, versioned, tested code operating on an approved ruleset.

If your change would let a model's output reach a user as a fiscal figure without passing through
`TaxEngine` and an approved ruleset, stop and escalate instead.

---

**Building a screen?** Read `docs/UI-PATTERNS.md` first and compose from
`resources/js/components/panel`. A page that writes its own sizes fails
`noRawStyling.test.ts`.

## 1. Hard prohibitions (`BLUEPRINT.md` §28)

You **MUST NOT**:

1. Invent an ANAF endpoint, URL, form field, or error code you have not verified.
2. Invent legislation, article numbers, or citations.
3. Introduce a tax rate, threshold, or ceiling without a `source_section_id`.
4. Modify a tax rule without a test.
5. Store a credential in plaintext.
6. Use a float for money. Integer minor units or `Money` value objects only.
7. Query across tenants without an authorization scope.
8. Disable, skip, or baseline a test or linter to make CI pass.
9. Add a dependency without justification recorded in your handoff note.
10. Add dead code, commented-out code, or scaffolding "for later".
11. Wrap Laravel/PHP standard behaviour in a pointless abstraction.
12. Log a request body on a sensitive endpoint.
13. Send documents to an external LLM implicitly — consent + audit event required (ADR 0005).
14. Persist LLM output as truth without schema validation.
15. Poll where a Reverb event exists for the same lifecycle.
16. Use a fragile CSS/XPath selector where a role/label selector exists.
17. Overwrite a raw official source. Ever. New version, new row.
18. Retroactively change a submitted declaration.
19. Hand-edit fiscal data in a production database.
20. Touch SPV, keys, or documents without a threat-model note in your handoff.

**If you cannot complete a task without breaking one of these, stop and escalate.**
A blocked task reported honestly is a success. A task completed by breaking a rule is a failure
that costs more to undo than it saved.

---

## 2. Never fabricate

This project's entire value is that every number traces to an official source. Fabrication is the
one failure mode that destroys it, and it is worse than an unfinished task.

- Do not invent fiscal values, dates, thresholds, or citations.
- Do not invent API responses, DOM structures, or file formats you have not observed.
- Do not write a plausible-looking placeholder that reads as real. Mark it: `NEDECIS` /
  `nu este încă decis`, or `@todo(#issue): <what is unknown>`.
- If you need a fact you cannot verify, say so in your handoff note under **Open questions**.
- Do not claim a test passes without running it. Paste the actual output.

---

## 3. Definition of done

A task is done when **all** of these hold:

- [ ] Code implements exactly what was asked — no more, no less.
- [ ] Tests written and **actually executed**, with output pasted into the handoff note.
- [ ] Quality suite passes for your language (§7).
- [ ] A handoff note exists at `docs/handoff/<NNN>-<slug>.md` (§5).
- [ ] `docs/STATUS.md` updated.
- [ ] Any new decision recorded as an ADR in `docs/adr/`.
- [ ] Anything you could not do is stated explicitly, not silently dropped.

"It should work" is not done. Run it.

---

## 4. Before you write code

1. Read `BLUEPRINT.md` for your bounded context.
2. Read `docs/ARCHITECTURE.md` and the ADRs in `docs/adr/`.
3. Read `docs/STATUS.md` to see what exists and what is in flight.
4. Read the handoff notes of the tasks yours depends on.
5. **Search for existing code before writing new code.** Reuse beats reinvention.
6. If your task touches a module you did not build, read its handoff note first.

---

## 5. Handoff notes — how the next agent continues your work

Every agent writes `docs/handoff/<NNN>-<slug>.md` before finishing. This is not paperwork; it is
the only channel through which the next agent learns what you know. Write it for someone with the
full blueprint but zero memory of your session.

```markdown
# <NNN> — <Task title>

**Milestone:** M<n>   **Status:** complete | partial | blocked
**Date:** YYYY-MM-DD

## What was built
<Plain description. Name the entry points.>

## Files created / changed
- `path/to/file.php` — what it does and why it exists

## Key decisions
<Decisions a future agent would otherwise re-litigate, and the reason. Link ADRs.>

## How to verify
<Exact commands, and their real output.>

## Interfaces exposed to other modules
<Contracts, DTOs, events, endpoints others may depend on.>

## What is NOT done
<Explicit gaps. Never leave these implicit.>

## Open questions
<Anything needing a human or another agent's decision.>

## Sources consulted
<URLs, doc sections, blueprint §refs. Mandatory for anything fiscal.>
```

---

## 6. Architecture rules

- **Modular monolith.** Modules live in `apps/api/app/Domain/<Module>/`.
- A module **never** imports another module's Eloquent models. Cross-module traffic goes through
  DTOs, contracts/interfaces, application services, or domain events. `deptrac` enforces this.
- No fiscal logic outside `Domain/TaxEngine` — not in controllers, form requests, jobs, React, or
  repositories.
- Tax rules are as close to pure functions as possible: no HTTP, no Eloquent, no `now()`, no env,
  no randomness, no cache. Every input is injected.
- Create a module only when it has real code. No empty directories for future milestones.
- All public IDs are UUIDv7/ULID. Never expose an incremental id.
- Timestamps stored UTC. Fiscal calendar dates are `DATE`. Scheduling/presentation uses
  `Europe/Bucharest`, always explicit.

---

## 6b. Dependencies and code quality — read these too

Two further binding documents, both normative:

- **`docs/DEPENDENCY-POLICY.md`** — pin *every* package to an exact, current, CVE-free version;
  commit every lockfile; `npm ci` not `npm install`; digest-pinned images in production; justify
  every new dependency in your handoff note.
- **`docs/CODE-QUALITY.md`** — DRY as §19.2 defines it (remove duplicated domain knowledge, do not
  abstract incidental similarity), zero dead code, zero commented-out code, no speculative
  scaffolding, naming and annotation rules.

## 7. Quality gates by language

Run these before declaring done. Paste real output.

**PHP** — `composer validate`, `composer audit`, `vendor/bin/pint --test`,
`vendor/bin/phpstan analyse`, `vendor/bin/pest`, `vendor/bin/deptrac analyse`
- `declare(strict_types=1);` in every file. PSR-12. Return types everywhere.
- Readonly DTOs/value objects. Enums for finite states. Constructor injection.
- No facades or service locators inside domain core.

**Python** — `uv lock --check`, `ruff format --check`, `ruff check`, `pyright`, `pytest`
- Type hints on all public APIs. Strict Pydantic models. Structured logging.

**TypeScript** — `eslint`, `tsc --noEmit`, tests, build
- Strict mode. `any` is forbidden without a reviewed comment.
- Runtime validation at every API boundary. No client-side tax calculation as source of truth.

---

## 7b. Testing against Postgres when other agents are working

Two rules, both learned the hard way:

**A green sqlite run is not evidence.** Sqlite accepts loose types and hands every test a fresh
in-memory database, which hides both type errors and state leakage. Four separate defects reached
`main` this way — a `uuid` column fed `'subject-1'`, fixture rows colliding on the second run, a
`CREATE FUNCTION` that made migrations non-repeatable, and a self-referencing FK declared inline.
**Run against Postgres before claiming done.**

**Use the running `anaf-app` container. Do not build a throwaway one.**
`anaf-app` already has every extension (`pdo_pgsql`, `intl`, `sodium`, `redis`, `bcmath`, `pcntl`)
and answers in ~90 ms. Building a fresh `php:8.5-cli-trixie` and `apt-get install`-ing `intl` costs
**minutes, every time**, and several agents have done it repeatedly. Measured on this machine:

| | |
|---|---|
| `docker exec anaf-app-1 …` | 88 ms overhead |
| fresh `docker run php:8.5-cli-trixie` | 357 ms + minutes of `apt-get` |
| full test suite in `anaf-app` | **8.7 s** |
| PHPStan level 9, 776 files | **30 s** |

```sh
docker exec anaf-app-1 sh -c 'DB_CONNECTION=sqlite vendor/bin/pest'
docker exec anaf-app-1 sh -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G'
```
If the container is not running: `docker compose up -d app`. Only build your own image if you are
changing the image itself.

**Run the scoped suite while iterating, the full suite once at the end.**
`vendor/bin/pest --filter=YourModule` takes ~2 s and catches nearly everything. Save the full
double-run-on-Postgres for your final verification — that requirement stays, it has caught more
real defects than anything else, but it does not need to run after every edit.

**Do not share one Postgres database with a concurrently running agent.** Parallel agents each
calling `migrate:fresh` against the same database will truncate each other's data mid-run and
produce failures that belong to nobody's code. If another agent is active (check
`docs/STATUS.md` → *Files currently being worked on*), create your own database first:

```sh
docker exec anaf-postgres-1 psql -U <user> -c 'CREATE DATABASE kern_<yourtask>;'
# then run with -e DB_DATABASE=kern_<yourtask>
```

When you see failures in a module you did not touch, suspect this before you suspect the code —
and never "fix" another agent's test to make your run green.

## 8. Security rules that bind every module

- Encrypt at application level: CNP, ID document, address, phone, IBAN, account number, SPV
  username, any token, documents, bank details, identifiable counterparties, private fiscal notes,
  OCR-extracted data, transaction descriptions containing PII.
- Ciphertext AAD binds `subject_id | table | field | record_uuid`. Moving ciphertext between rows
  must fail to decrypt.
- Blind index = `HMAC-SHA-256(index_key, normalized_value)`. Never a bare hash for low-entropy values.
- Every business query is scoped to `tax_subject_id`. Cross-tenant isolation has automated tests.
- Never log PII, credentials, tokens, or request bodies on sensitive endpoints.
- Broadcast events carry status and progress only — never document text, never PII. The client
  re-fetches protected data over authorised HTTPS.

---

## 9. Fiscal work — additional requirements

Any change that can affect a computed tax figure must carry:

- the `source_section_id` for every value,
- the effective date range,
- a test derived from an official ANAF example where one exists,
- a historical regression run proving untouched years produce identical results,
- `status: CANDIDATE` until a human approves. **No agent may set `APPROVED`.**

---

## 10. Working with other agents

- Your handoff note is the interface. Assume no shared memory.
- Do not edit files another agent is actively working on; check `docs/STATUS.md`.
- If you find a bug outside your scope, record it in `docs/STATUS.md` under *Known issues* rather
  than fixing it silently in an unrelated change.
- Prefer extending an existing pattern over introducing a competing one. If you must introduce one,
  write an ADR explaining why the existing pattern was insufficient.

---

## 11. Escalate instead of guessing

Stop and report when: a fiscal value has no verifiable source; an external system behaves
differently than documented; a rule in this file blocks the task; two blueprint sections conflict;
the task requires a business, legal, or compliance decision.

Escalation costs minutes. A fabricated fiscal value discovered after launch costs the product.
