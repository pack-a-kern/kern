# GLOSSARY — Romanian fiscal and project-internal terms

Two kinds of entry live here, and they are labeled so you never confuse one for the other:

- **Legal/official term** — a term with a specific meaning in Romanian tax law or ANAF
  administrative practice. Where this document is not certain enough of the precise legal
  definition to state it as fact, it says so explicitly and describes the term's role in this
  project instead. **Never treat a description here as a substitute for reading the actual source
  section** (`source_documents` / `source_sections`) a `TaxRule` cites — this glossary orients a
  new engineer, it does not replace `TaxKnowledge`.
- **Project-internal term** — a name this codebase invented (a table, a concept, an entity) that
  has no independent legal meaning outside this system.

---

## Legal / official terms

### CNP (Cod Numeric Personal)
The Romanian personal numeric identifier assigned to a physical person. Treated in this project as
a high-sensitivity identifier: encrypted at application level (`BLUEPRINT.md` §4.3), never duplicated
across tables beyond `pf_profiles`, and looked up only via a blind index, never a plaintext query
(`BLUEPRINT.md` §4.4, §6.2). This glossary does not restate its exact issuance rules (birth date/sex
encoding, county digit, checksum) — that is a legal/administrative fact to verify against an
official source if the system ever needs to validate or parse it, not to assume from memory.

### CASS (Contribuția de Asigurări Sociale de Sănătate)
The Romanian health-insurance social contribution. This project computes it exclusively inside
`Domain/TaxEngine`, per year, via a versioned `RuleVersion` tied to approved `source_section`s
(ADR 0004) — the applicable thresholds, minimum-wage references, and income categories it applies
to vary by year and are **project-internal `fiscal_parameter`/`tax_rule` data**, not something to
hard-code or recall from training data. See `BLUEPRINT.md` §10.1 for the historical categories this
project must model (investment income, rents, etc.).

### CAS (Contribuția de Asigurări Sociale)
The Romanian pension social-insurance contribution. Applies to certain income categories/subject
types and, like CASS, is computed only through a versioned `TaxEngine` rule tied to a
`source_section` — never a literal rate in application code.

### D212 ("Declarația Unică" / Formular 212)
The single-declaration form ANAF uses for a PF's income tax and social-contribution
self-declaration (`BLUEPRINT.md` §12, §37: "Depunere declarație unică și alte formulare SPV-PF"). In
this project it is not one static schema: `form_definitions`/`form_versions` version its schema,
applicability window, instructions, and validators over time (`BLUEPRINT.md` §6.10, §12.2), because
the form itself changes year to year.

### SPV (Spațiul Privat Virtual)
ANAF's authenticated online portal for taxpayer correspondence and declaration submission. The
mandatory channel this project automates against — via an official API/web-service where one is
documented, and via an isolated Playwright browser worker only for the portal-only flows that
have no such API (ADR 0003, `BLUEPRINT.md` §13).

### ANAF (Agenția Națională de Administrare Fiscală)
Romania's national tax administration agency — the fiscal authority whose declarations,
guidance, and portal (SPV) this project integrates with. Trust tier B in the source hierarchy
(`BLUEPRINT.md` §8.1), below `legislatie.just.ro`/Monitorul Oficial (tier A).

### BNR (Banca Națională a României)
Romania's central bank; the canonical source for official RON exchange rates used in any fiscal
FX conversion (`BLUEPRINT.md` §7). Trust tier D in the source hierarchy (`BLUEPRINT.md` §8.1) — used
specifically for exchange-rate values, not for tax rules themselves.

### PF (Persoană Fizică)
A physical/natural person taxpayer — the only `TaxSubject` type this project implements business
logic for in v1 (`BLUEPRINT.md` §2.2). Everything else (`PFA`/`II`/`IF`/`SRL`) exists as an enum
value with no speculative logic behind it yet.

### PFA (Persoană Fizică Autorizată)
An authorized-natural-person business form under Romanian law — a self-employed individual
operating under a personal business authorization, distinct from a PF's ordinary personal income.
Planned as the first real test of this project's extension points (`BLUEPRINT.md` §12/§32,
Milestone 12) — **not implemented**; do not add speculative `PfaProfile` logic ahead of that
milestone (`docs/CODE-QUALITY.md` §2).

### II / IF (Întreprindere Individuală / Întreprindere Familială)
Individual enterprise / family enterprise — other Romanian self-employment/business legal forms
distinct from PFA. Present only as `TaxSubject` enum values (`BLUEPRINT.md` §2.2, §5.2); this project
implements no business logic for them in v1.

### SRL (Societate cu Răspundere Limitată)
A Romanian limited-liability company — the corporate legal form this project's architecture must
support adding later without rewriting the PF-first core (`BLUEPRINT.md` §32–§33, §38-C). Not
implemented.

### Declarație rectificativă (rectificative declaration)
A corrective/amending declaration filed to replace or correct a previously submitted one. In this
project, a submitted `declaration` is immutable (`docs/AGENTS.md` rule 18); any post-submission
correction is modeled as a **new** `declaration` of `kind = RECTIFICATIVE` linked to the original,
never as an edit to the submitted row (`BLUEPRINT.md` §6.10, §12.3).

### Cedarea folosinței bunurilor (letting of the use of goods)
The Romanian tax-law income category covering income from letting property/assets for use —
principally residential/commercial rentals for the purposes this project models
(`rental_contracts`, `rental_accruals`, `BLUEPRINT.md` §6.5, §10.1). This glossary describes it by
its role in the schema; the precise legal scope of what counts under this category (which asset
types, which arrangements) is a source-section fact to confirm from `legislatie.just.ro`/Codul
fiscal via `TaxKnowledge`, not to assume here.

### Venit net (net income)
Taxable income after allowable deductions are applied to gross income, within a given category.
The exact deduction mechanics (flat-rate quota, actual-expense method, category-specific rules)
are year- and category-dependent and live in `TaxEngine`/`TaxKnowledge` as versioned rules
(ADR 0004) — this glossary names the concept, it does not state a current deduction percentage.

### Plafon (threshold / ceiling)
A statutory income threshold that changes tax treatment once crossed — e.g., the CASS
minimum-income threshold referenced throughout `BLUEPRINT.md` §10.1's dataset requirements. Always a
`fiscal_parameter_version` with a `source_section_id` and an effective date range
(`BLUEPRINT.md` §6.8) — never a literal number in application code (`BLUEPRINT.md` §2.3,
`docs/AGENTS.md` rule 3).

---

## Project-internal terms

### TaxSubject
The project's entity-agnostic representation of "a taxpayer" — a `User` owns or accesses one or
more `TaxSubject`s, each of a `type` (`PF`/`PFA`/`II`/`IF`/`SRL`). Deliberately separate from
`User` (application identity) so that one login can, in the target design, relate to multiple
taxpayers (e.g. an accountant with delegated access) without conflating "who is logged in" with
"whose taxes these are" (`BLUEPRINT.md` §2.2, §5.1–§5.3).

### TaxFact
A normalized, dated financial fact ready to feed a tax computation — the output of interpreting a
financial event (a transaction, a rental accrual, a deposit interest event, an AI document
extraction) for tax purposes, with a `status` of `PROPOSED`, `CONFIRMED`, or `IGNORED`
(`BLUEPRINT.md` §2.4, §6.7, §14.6). Only `CONFIRMED` facts feed a `TaxAssessment`.

### Ruleset
A versioned, reviewable bundle of tax rule implementations and their parameter references for a
given jurisdiction and fiscal year (`tax-rules/ro/<year>/`, `BLUEPRINT.md` §10.2). The YAML manifest
is a reviewable index into which `implementation_class` handles a given tax; it is not itself the
runtime source of truth for complex formulas, which live in code (`BLUEPRINT.md` §10.2, last line).

### Blind index
`HMAC-SHA-256(index_key, normalized_value)` — a deterministic, keyed hash stored alongside an
encrypted field specifically so an exact-match lookup (login by email, duplicate-CNP detection)
can run without decrypting the column. See ADR 0002 for why one key is global versus per-subject,
and for the cross-tenant correlation tradeoff that decision accepts explicitly.

### DEK / KEK (Data Encryption Key / Key Encryption Key)
Envelope encryption's two key layers (ADR 0002, `BLUEPRINT.md` §4.2): a per-`TaxSubject` DEK
encrypts that subject's sensitive fields and documents; the DEK itself is never stored in
plaintext — it is wrapped by a KEK held in a KMS/secrets manager (OpenBao in this project). The
database only ever holds `encrypted_dek` and a key version, never a usable key.

### Envelope encryption
The overall pattern combining a DEK and a KEK (previous two entries) so that compromising the
database alone (without the KMS) yields no usable plaintext. See ADR 0002 for the full decision,
including the documented tradeoff of the global blind-index key.

### CalculationTrace
The structured, reproducible record `TaxEngine` attaches to every assessment: which rule and
rule version ran, what inputs it received, what steps it took, and which `source_section_id`s
justify it — excluding PII not needed to justify the result (`BLUEPRINT.md` §11.4, ADR 0004). This is
what makes "why do I have this tax figure" answerable without reading old code.

### Tax subject membership (`subject_memberships`)
The project's authorization join between a `User` and a `TaxSubject`, carrying a role
(`OWNER`/`ADMIN`/`ACCOUNTANT`/`VIEWER`) and a validity window. Every business query is scoped
through this — there is no query that reaches subject data without going through a membership
check (`BLUEPRINT.md` §5.3, `docs/AGENTS.md` §8).
