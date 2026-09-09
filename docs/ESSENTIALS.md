# ESSENTIALS — read this, then only the 1–2 documents your task names

One page. Everything below has already caused a real defect in this project.

---

## 1. Never fabricate. This is the whole product.

Every fiscal number must trace to an official source. Do **not** invent: tax rates, thresholds,
legal citations, article numbers, ANAF endpoints, form field names, DOM selectors, API responses, or
file formats you have not observed.

If you need a fact you cannot verify: **stop and say so** in your handoff under *Open questions*.
A blocked task reported honestly is a success. A plausible-looking invention is the one failure mode
that destroys this product's reason to exist.

Mark unknowns `NEDECIS` / `nu este încă decis`, never a realistic-looking placeholder.

## 2. The hard prohibitions

No float for money (integer minor units / `Money`). No plaintext credentials. No cross-tenant query
without a scope. No disabling a test or linter to go green — no baselines, no `@phpstan-ignore`
without a comment justifying it. No dead code, no commented-out code, no speculative scaffolding.
No PII in logs. No unapproved fiscal value reaching a final assessment. **No agent may set
`APPROVED`.**

## 3. Traps that have already bitten

- **Eloquent casts run at assignment, not save.** Models must set their UUIDv7 in the **constructor**, or the AAD binding has no record id. See `handoff/005`.
- **Pint's `strict_comparison` rewrites `!=` to `!==`.** Correct for scalars, **wrong for objects** — `!==` on two distinct instances is always true. Compare with `<=>`, `->equals()`, or a scalar. This produced a real bug in `Domain/Wallet`.
- **A green sqlite run is not evidence.** Sqlite accepts loose types and gives each test a fresh database, hiding type errors and state leakage. Four defects reached `main` this way.
- **Migrations that create database objects must be idempotent** — `CREATE OR REPLACE FUNCTION`, not `CREATE FUNCTION`. `migrate:fresh` drops tables, not functions, and never calls `down()`.
- **Self-referencing foreign keys** must be added in a second `Schema::table()` call; Postgres rejects them inline.

## 4. How to run things — fast

Use the **already-running `anaf-app` container**. It has every extension and answers in ~90 ms.
Do **not** build a throwaway `php:8.5-cli-trixie` and `apt-get install intl` — that costs minutes,
every time.

```sh
docker exec anaf-app-1 sh -c 'DB_CONNECTION=sqlite vendor/bin/pest --filter=YourModule'   # ~2 s
docker exec anaf-app-1 sh -c 'php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G'  # ~30 s
```

Iterate with the **scoped** suite. Run the full suite **once**, at the end, against **real Postgres,
twice**, to prove no state leaks. If another agent is active, create your own database:

```sh
docker exec anaf-postgres-1 psql -U <user> -d postgres -c 'CREATE DATABASE taxos_<task>;'
```

Failures in a module you did not touch usually mean a shared database, not a real bug.

## 4b. Do not spawn subagents

Do the work yourself. An agent that delegates pays for its own exploration **and** the subagent's,
produces nothing while both run, and returns a summary of a summary. One task cost 150k tokens and
wrote zero files this way.

If a task is too large, say so and stop — a task returned as "too big, here is how I would split it"
is a useful result. Silently fanning out is not.

## 5. Definition of done

Code does exactly what was asked — no more. Tests written **and actually run**, with real output
pasted. `pint --test`, `phpstan` (level 9, no baseline), `pest`, `deptrac` all pass. A handoff note
exists at `docs/handoff/<NNN>-<slug>.md` using the template in `AGENTS.md` §5. Anything you could
not do is stated explicitly, not silently dropped.

## 6. Where the rest lives

`AGENTS.md` (full rules) · `CODE-QUALITY.md` · `DEPENDENCY-POLICY.md` (pin exact versions) ·
`ARCHITECTURE.md` · `adr/` · `handoff/` · `reference/` (verified external facts) · `STATUS.md`.

**Read only the ones your task names.** They are indexed in `docs/README.md`.
