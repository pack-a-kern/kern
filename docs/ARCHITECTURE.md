# ARCHITECTURE — module map, boundaries, and data flow

This file is the map. `BLUEPRINT.md` is the territory. Where they disagree, `BLUEPRINT.md` wins and the
disagreement should be reported (`docs/AGENTS.md`, header).

**Reading path for a new agent:** `BLUEPRINT.md` (blueprint) → `docs/AGENTS.md` (binding rules) →
this file (map) → `docs/adr/*` (why the map looks like this) → `docs/DEPENDENCY-POLICY.md` and
`docs/CODE-QUALITY.md` (how code gets written and what it may depend on) → `docs/STATUS.md` (what
actually exists right now) → the handoff note of whatever you're extending.

---

## 1. Three planes, one system

```text
┌─────────────────────────┐      ┌──────────────────────────┐      ┌───────────────────────┐
│      Laravel plane       │      │      Python plane         │      │    Browser plane       │
│   apps/api (PHP 8.5)     │      │  python-api / -worker      │      │    spv-browser          │
│                          │      │  (FastAPI, Python 3.14)    │      │  (Playwright, Chromium) │
│  ALL business tables.    │◄────►│  Document parsing, OCR,    │      │  ANAF SPV portal only.  │
│  ALL tax logic.          │ HTTP │  embeddings, LLM calls.    │      │  No DB credentials.     │
│  Owns Postgres writes.   │ +    │  No direct DB access to    │      │  Reached only through   │
│                          │ jobs │  Laravel business tables.  │      │  the internal service   │
└─────────────────────────┘      └──────────────────────────┘      │  contract (ADR 0003).   │
                                                                     └───────────────────────┘
```

These are separate deployables **because they have different runtimes and different threat
profiles**, not because of scale (ADR 0001). Laravel is the only plane with business-table write
access. Python and the browser worker reach it only through:

- an internal, authenticated API (service-to-service, short-TTL signed tokens / mTLS — see ADR
  0003 §13.4 contract for the browser worker's shape; the same principle governs `python-api`);
- signed job payloads carried over the queue (Redis/Horizon), never raw DB credentials;
- object IDs and pre-signed storage URLs for files, never direct MinIO credentials shared beyond
  what a job needs;
- results validated against a JSON Schema / Pydantic model before Laravel accepts them as fact
  (`BLUEPRINT.md` §1.2, §14.4).

Python never writes to a Laravel business table directly, under any circumstance. If a Python job
needs a `TaxFact` or `document_extraction` row created, it calls the internal API and Laravel does
the write, applying the same authorization and validation any other write path would.

---

## 2. Laravel: modular monolith

```text
apps/api/app/Domain/<Module>/
```

Per ADR 0001, a module directory is created only when it has real code — this project has not
reached Milestone 1 yet, so **no `Domain/` modules exist at the time of writing**. The list below
is the target shape from `BLUEPRINT.md` §2.1, not a claim that these directories are populated.

| Module | Owns | Never does |
|---|---|---|
| `Identity` | `User`, auth, passkeys/MFA, sessions | Know what a `TaxSubject` is for fiscally |
| `Subjects` | `TaxSubject`, `subject_memberships`, PF/PFA/II/IF/SRL profiles | Compute a tax figure |
| `Wallet` | `financial_accounts`, balances, deposits | Decide tax treatment of a transaction |
| `Ledger` | `financial_transactions`, `transaction_splits`, categorization | Know about declarations |
| `Documents` | `documents`, `document_derivatives`, `document_extractions`, upload lifecycle | Parse files itself (that's Python) or decide tax facts |
| `TaxKnowledge` | `official_sources`, `source_documents/_versions/_sections`, `fiscal_parameters`, `tax_rules/_versions`, FX rates | Run a calculation |
| `TaxEngine` | `TaxContext`, rule implementations, `TaxAssessment(Line)`, `CalculationTrace` | Touch HTTP, Eloquent, `now()`, env, randomness, cache (ADR 0004) — the only module allowed to produce a fiscal figure |
| `Declarations` | `form_definitions/_versions`, `declarations`, submission snapshots | Compute the figures it maps (consumes `TaxAssessment`, never recomputes) |
| `Spv` | `spv_connections/_sessions/_sync_runs/_messages` | Hold a browser session itself (that's the `spv-browser` container, reached via the contract in ADR 0003) |
| `Notifications` | Domain event → user-facing notification, Reverb broadcast payload shaping | Broadcast PII or document text (`BLUEPRINT.md` §16) |
| `Audit` | `audit_events`, `audit_checkpoints`, hash-chain verification | Get bypassed by another module writing its own audit trail |

**Cross-module rule (ADR 0001):** a module never imports another module's Eloquent models. All
cross-module traffic goes through DTOs, contracts/interfaces, application services, or domain
events, and this is enforced by `deptrac` in CI — not by convention.

---

## 3. Data flow: financial event to declaration (`BLUEPRINT.md` §2.4)

```text
Financial Event                    (Wallet/Ledger: a transaction, a deposit interest event,
      │                             a rental payment, a document extraction proposing income)
      ▼
Normalized Tax Fact                (TaxKnowledge/TaxEngine boundary: a `TaxFact` row —
      │                             PROPOSED if AI-sourced, CONFIRMED only after human review
      │                             for high-impact fields, §14.6)
      ▼
Tax Rule Set                       (TaxEngine: a versioned `RuleVersion`, resolved for the
      │                             fiscal year and `as_of` date being assessed — ADR 0004)
      ▼
Tax Assessment                     (TaxEngine: `TaxAssessment` + `TaxAssessmentLine[]` +
      │                             `CalculationTrace`, reproducible from the same inputs)
      ▼
Tax Obligation                     (TaxEngine/Declarations boundary: what is owed, by when)
      ▼
Declaration Mapping                (Declarations: `D212Draft` → form-version mapping →
                                     validation → artifact → immutable snapshot at
                                     READY_TO_FILE, per `BLUEPRINT.md` §12.3)
```

A `TaxAssessment` can be recomputed if a ruleset changes; a `declaration` that has been snapshotted
or submitted cannot be — a new draft/rectificative is created instead (`docs/AGENTS.md` rule 18).

The **source-of-truth chain** (`BLUEPRINT.md` §30) that every fiscal figure must be able to display:

```text
User/Financial Data → TaxFact → Rule implementation → RuleVersion →
   approved source section(s) → immutable source document hash
```

---

## 4. Where AI sits, and where it is not allowed to sit

- **RAG explanation** (`TaxKnowledge`, pgvector — ADR 0006): answers "why," cites
  `source_section_id`s with `effective_from/until`, never computes a figure (`BLUEPRINT.md` §15.5).
- **Document extraction** (`Documents` + `python-worker`, ADR 0005/0007): produces
  `document_extractions` rows with `status = PROPOSED`; only a human review can move a resulting
  `TaxFact` to `CONFIRMED` for high-impact fields.
- **`TaxEngine`** is the only module permitted to produce a number that reaches a user as a
  computed tax figure (ADR 0004, `docs/AGENTS.md` §0). No AI output reaches that path directly,
  ever.

---

## 5. Realtime

Laravel Reverb runs in its own container (`BLUEPRINT.md` §3.2), separate from `php-fpm`. Broadcasts
carry status/progress only — document text, OCR output, and PII never go over the wire; the client
re-fetches protected data over authorized HTTPS after being told something changed
(`BLUEPRINT.md` §16, `docs/AGENTS.md` §8). UI does not poll where a Reverb event already exists for
the same lifecycle (`docs/AGENTS.md` rule 15).

---

## 6. Network segmentation (`BLUEPRINT.md` §3.3)

```text
edge_net        — nginx only
app_net         — Laravel, Horizon, Reverb, python-api
processing_net  — python-worker
data_net        — postgres, redis, minio  (no outbound internet)
browser_net     — spv-browser             (no DB credentials)
```

`postgres`, `redis`, and `minio` are never internet-reachable. `python-worker` and `spv-browser`
never receive Laravel DB credentials. No container receives `/var/run/docker.sock`.

---

## 7. See also

- `docs/adr/0001-modular-monolith.md` — why modules, not services; why Python is separate anyway.
- `docs/adr/0002-envelope-encryption.md` — per-subject DEK + global blind-index key.
- `docs/adr/0003-playwright-for-spv-pf.md` — API-first, Playwright only where no API exists.
- `docs/adr/0004-tax-engine-deterministic.md` — why `TaxEngine` is the sole source of figures.
- `docs/adr/0005-external-document-ai.md` — local text-layer first, external OCR gated.
- `docs/adr/0006-pgvector-over-qdrant.md` — vector search inside Postgres, not a second service.
- `docs/adr/0007-provider-agnostic-ai.md` — one wire protocol, not one SDK per vendor.
- `docs/DEPENDENCY-POLICY.md` — what may be added, pinned, and how it's justified.
- `docs/CODE-QUALITY.md` — DRY nuance, no dead code, non-negotiables, test expectations.
- `docs/GLOSSARY.md` — Romanian fiscal and project-internal terms.
