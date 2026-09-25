# PF coupling audit — Milestone 12 extension readiness

**Investigated:** 2026-09-09, ahead of and during the PFA extension proof
(`docs/handoff/034-pfa-extension.md`). Scope: every module a PFA `TaxSubject` would touch —
`Identity`, `Subjects`, `Wallet`, `Documents`, `TaxKnowledge`, `TaxEngine`, `Declarations`, `Spv`,
`Audit`, `Fx`, and the `App\Support\*` security core — read directly, not sampled from handoff
notes. Every file reference below was opened and read; nothing here is inferred from a docblock's
own claim about itself.

## Verdict

**`BLUEPRINT.md` §38.B held.** A second subject type (`SubjectType::PFA`) was added with:

- one new table (`pfa_profiles`), one new model, one new DTO, one new action, one new exception —
  all in `App\Domain\Subjects`;
- **zero changes** to `Kern\Platform\Identity`, `App\Domain\Wallet`, `Kern\Documents`,
  `App\Domain\Fx`, `App\Domain\Spv`, `App\Domain\Audit`, `App\Domain\Declarations`, or any file
  under `App\Support\**` (encryption, key management, blind indexing, logging);
- **zero changes** to `App\Domain\TaxEngine` — `RuleResolver`, `ParameterResolver`, `RuleInvoker`,
  `TaxAssessor`, `TaxAssessmentRecorder`, `TaxContext`, `TaxFactRepository` are byte-for-byte what
  Milestone 5 shipped; a PFA-tagged rule resolves and runs by adding *data* rows
  (`tax_rules`/`tax_rule_versions`/`fiscal_parameters`/`fiscal_parameter_versions`), not code;
- **zero new `deptrac.php` grants** — confirmed by running `deptrac analyse` before and after this
  task's changes: 0 violations both times, and the new code needed no layer this task's module
  (`Subjects`) did not already have (`Subjects -> Support`, `Subjects -> Audit`).

This is the strongest form of the claim the blueprint asks for: not "we didn't need to touch it"
asserted in prose, but a passing `deptrac analyse` and a green test suite proving it, per
`docs/handoff/034-pfa-extension.md`'s "How to verify".

**Three real findings did surface**, none of them blocking, all outside this task's declared
scope to fix (`apps/api/app/Support/**`, `apps/api/app/Http/**` are not in
`docs/handoff/034-pfa-extension.md`'s path list). They are recorded below with enough detail that
a future task can act on them without re-deriving the investigation.

---

## Findings requiring attention (not fixed here — out of scope)

> **Status update, 2026-09-08 (`docs/handoff/036-identifier-schema-fix.md`, ADR 0011 —
> `docs/adr/0011-canonical-fiscal-identifier.md`): Findings 2 and 3 are now RESOLVED.** The task
> that resolved them was briefed as closing "findings 1 and 3"; reading this document to scope
> the work found that the two schema defects it was actually asked to fix (a duplicated CNP blind
> index, and a single unqualified uniqueness namespace across identifier kinds) are this
> document's Findings 3 and **2**, not 1. Finding 1 (the logging-redaction gap) turned out to
> already be fixed in `app/Support/Logging/SensitiveDataProcessor.php` — by unrelated, undocumented
> work with no corresponding handoff note — so it is marked resolved below too, for completeness,
> but that fix is **not** part of this task's own change. See each finding's own "Resolution"
> note.

### 1. `SensitiveDataProcessor`'s key-name redaction list has no PFA/business-identifier entry

**Resolution: RESOLVED, but not by this task.** `app/Support/Logging/SensitiveDataProcessor.php`'s
`SENSITIVE_KEYS` already includes `'cui'`, `'codfiscal'`, `'nrregcom'`, `'nrregistrucomert'`, and
`'fiscalidentifier'` alongside `'cnp'`/`'iban'`, with an inline comment citing this finding by
name. No handoff note documents when or by whom this was done, and `app/Support/Logging/**` is
outside `docs/handoff/036-identifier-schema-fix.md`'s file scope, so it was verified (read
directly) but not touched or re-tested here. Whoever next touches `SensitiveDataProcessor` should
write the missing handoff note this fix never got.

`app/Support/Logging/SensitiveDataProcessor.php:92` — `SENSITIVE_KEYS` includes `'cnp'` and
`'iban'` (any context array key that normalises to one of these has its value fully redacted,
regardless of shape), but **not** `'cui'` or any generic "business fiscal identifier" name. The
pattern-based redaction pass has the same gap: `CNP_PATTERN` (`/\b[1-8]\d{12}\b/`, `line 99`) is
specific to CNP's 13-digit shape and would not catch a CUI or an ONRC-style registration number
(`J40/1234/2020`) appearing in free text.

**Why this matters for PFA specifically**: `App\Domain\Subjects\Actions\RegisterPfaSubject` never
logs `registrationNumber` (confirmed by reading the whole class — no `Log::`/`log()` call exists
in it), so nothing is unsafe *today*. But the redaction list was clearly built with only PF's two
sensitive identifiers in mind, and the next module that logs a PFA registration number, CAEN
code context, or business identifier in a context array keyed something other than `cnp`/`iban`
gets **no automatic protection** — the safety net silently does not extend itself to a second
subject type's identifiers the way, say, `ScopedToTaxSubject` does.

**Recommendation for a future task** (not done here — `app/Support/Logging/**` is outside this
task's path scope, and touching it would mean re-verifying every existing redaction test):
add `'registrationnumber'` (and/or `'cui'`) to `SENSITIVE_KEYS`. Do not add a CUI-shaped
`preg_replace` pattern without a verified, official CUI format — Romanian CUI length varies (2–10
digits, sometimes an `RO` prefix) enough that a loose pattern risks either missing real values or
producing false-positive redactions of unrelated numbers, which is its own defect
(`docs/AGENTS.md` §2 — no unverified assumption baked into security-relevant code).

### 2. `tax_subjects.fiscal_identifier_blind_index` is a single global-uniqueness namespace shared across every future subject type

`database/migrations/2026_09_08_200001_create_tax_subjects_table.php` gives `tax_subjects` one
generic `fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` pair, with the blind index
**globally unique across the whole table regardless of `type`**. `RegisterPfSubject` populates it
with the CNP (redundantly with `pf_profiles.cnp_blind_index` — see Finding 3). Nothing in the
schema or in `RegisterPfSubject` scopes that uniqueness constraint to `type = 'PF'`.

Had `RegisterPfaSubject` populated the same column with a registration number (the naively
"obvious" thing to do, mirroring PF), a business's registration number and some unrelated
person's CNP would sit in the same uniqueness namespace — a CNP-shaped string and a registration-
number-shaped string are extremely unlikely to collide as raw values, but "extremely unlikely" is
not "impossible," and mixing two identifier *kinds* under one global constraint is a design smell
independent of collision probability: the column's meaning ("this subject's fiscal identifier")
silently becomes type-dependent the moment a second type uses it differently, with nothing in the
schema enforcing or documenting that per-type meaning.

**Resolution at the time this finding was written**: `RegisterPfaSubject` did **not** populate
`tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` at all — it avoided the
collision risk by not sharing the column, mirroring how `pf_profiles.cnp_blind_index` provided
PF's own uniqueness independently of the shared column. That avoided extending the risk without
resolving the underlying asymmetry this finding named as needing a deliberate choice.

**RESOLVED, 2026-09-08** (`docs/adr/0011-canonical-fiscal-identifier.md`,
`docs/handoff/036-identifier-schema-fix.md`): the underlying risk is now closed at its root instead
of avoided. `App\Domain\Subjects\Enums\FiscalIdentifierKind` (`CNP`, `CUI`) binds the identifier's
*kind* into the value that gets hashed (`FiscalIdentifierKind::keyedValue()`), so a CNP and a
business identifier that normalise to the same raw digits can never produce the same blind index —
the collision risk this finding described is gone regardless of how unlikely it already was.
`RegisterPfaSubject` now populates `tax_subjects.fiscal_identifier_*` exactly the way
`RegisterPfSubject` does, tagged `FiscalIdentifierKind::CUI`; the column is genuinely shared, and
the asymmetry this finding flagged no longer exists. `pfa_profiles.registration_number_ciphertext`/
`registration_number_blind_index` were removed (see Finding 3's resolution, which this task applied
symmetrically to PFA). Verified by `tests/Feature/Domain/Subjects/BlindIndexLookupTest.php`'s
cross-kind non-collision tests and `RegisterPfaSubjectTest.php`'s
`it('populates the canonical fiscal_identifier column on tax_subjects, tagged CUI, for a PFA
registration')`.

### 3. Pre-existing minor duplication: CNP's blind index is already stored twice for PF

Independent of PFA, reading `RegisterPfSubject` closely (`app/Domain/Subjects/Actions/
RegisterPfSubject.php`, lines 68–76 at the time this finding was written) showed the same CNP
blind index written to **both** `tax_subjects.fiscal_identifier_blind_index` and
`pf_profiles.cnp_blind_index` — two unique constraints independently enforcing the same invariant.
This predated the PFA extension task (Milestone 1) and was not introduced there; it was recorded
because Finding 2 above depended on understanding it, and because `docs/CODE-QUALITY.md` §1 names
exactly this shape ("a fiscal threshold, rule, or business invariant appearing twice is a defect —
extract it") as worth flagging when found. `RegisterPfaSubject` was written to **not** repeat this
duplication — a small, deliberate divergence from copying the PF pattern verbatim, made and
documented rather than done silently.

**RESOLVED, 2026-09-08** (`docs/adr/0011-canonical-fiscal-identifier.md`,
`docs/handoff/036-identifier-schema-fix.md`): the false docblock claim this finding's context
depended on ("CNP is encrypted here and nowhere else") is now true. `pf_profiles` no longer has a
`cnp_ciphertext`/`cnp_blind_index` column at all — the CNP lives solely in
`tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`, tagged
`FiscalIdentifierKind::CNP`. `RegisterPfSubject`'s duplicate-CNP check now queries `TaxSubject`
directly instead of `PfProfile`. Verified by
`tests/Feature/Domain/Subjects/RawDatabasePlaintextTest.php`'s schema assertion that
`pf_profiles.cnp_ciphertext`/`cnp_blind_index` do not exist, and by the full `RegisterPfSubjectTest`/
`BlindIndexLookupTest` suites continuing to pass unmodified in their duplicate-detection behaviour.

---

## Findings: clean (checked, and no coupling found)

These are worth recording explicitly — `docs/AGENTS.md`'s own framing ("we checked and it was
fine is evidence about the architecture") means a silent audit is not a complete one.

- **`App\Domain\Wallet`** — `FinancialAccount`, `FinancialTransaction`, `Deposit`, and every other
  Wallet model use `ScopedToTaxSubject`/`tax_subject_id` only; grepped for `SubjectType`/`PfProfile`
  across the whole module — zero matches. `OpenFinancialAccount` (used unmodified in this task's
  own `PfaEncryptionAndCoexistenceTest.php`) takes a bare `taxSubjectId` string and never inspects
  what kind of subject it belongs to.
- **`Kern\Documents`** — same result: zero `SubjectType`/`PfProfile` matches in application
  code. `DocumentAiConsent`'s docblock mentions `PfProfile` once, only as a precedent citation for
  its own self-referential-primary-key pattern (identical reasoning, not a dependency) — read in
  full to confirm this is documentation, not an import.
- **`App\Domain\Declarations`** — `Declaration`/`DeclarationSubmission` scope to `tax_subject_id`
  only; "this declaration is a PF form" is entirely a **data** fact (which `form_definitions`/
  `form_versions` row a `Declaration` points at), never a code branch on subject type. D212 being
  "the PF declaration unică" lives nowhere in `App\Domain\Declarations`' own code.
- **`App\Domain\Spv`** — zero `SubjectType` matches. Notably, `AnafCertificateApiTransport`'s own
  docblock already documents ANAF's certificate-info response as carrying **both** `"cnp"` and
  `"cui"` fields side by side (`app/Domain/Spv/Transport/AnafCertificateApiTransport.php:27`) —
  this module was already written expecting a natural-person-or-business certificate holder,
  independent of this task.
- **`App\Domain\TaxKnowledge`** — `tax_rules.subject_type` (`database/migrations/
  2026_09_08_301004_create_tax_rules_table.php`) already exists as a plain, nullable string
  (deliberately not a shared enum with `Subjects`, per that migration's own docblock) and already
  accepts an arbitrary value — `'PFA'` needed no schema change, confirmed by this task's own
  `PfaRuleExtensionTest.php` inserting exactly that value.
- **`App\Domain\Rental`'s `TenantType::PF`** (`app/Domain/Rental/Enums/TenantType.php`) is a
  different concept entirely — the counterparty's type in a lease (`PF|PJ|UNKNOWN`), describing
  the *tenant*, never the landlord `TaxSubject`. Confirmed it is not `Subjects\Enums\SubjectType`
  reused or aliased; the name collision is coincidental and blueprint-literal (§6.5), not a coupling
  bug.
- **`App\Domain\Subjects\Queries\{ListSubjectsForUser,SubjectSummary}`** — read in full: entirely
  generic over `SubjectType` already (the query returns whatever `TaxSubject::$type` is; the enum
  value round-trips through `SubjectSummary::toArray()` without a single branch on which case it
  is). No change was needed here for PFA to show up correctly in this read model.
- **`App\Domain\TaxEngine`'s DTOs** (`TaxContext`, `TaxRuleInput`, `TaxFactSnapshot`,
  `TaxRuleOutcome`) carry **no subject-type field at all** — confirmed by reading every DTO in
  `app/Domain/TaxEngine/DataTransferObjects/`. This is a deliberate design already in place since
  Milestone 5 (`docs/handoff/020-tax-engine.md` decision #1: the caller states which rule codes to
  assess; the engine never infers applicability from a category or a subject type). It means the
  engine was subject-type-agnostic *before* this task existed, not because of anything this task
  added — the extension "worked" here because there was nothing PF-specific to remove.
- **`deptrac.php`** — before this task, `Subjects` already had `accesses($support, $audit)`; that
  grant is exactly what `RegisterPfaSubject` needed (it calls `AuditChainService::record()`, same
  as `RegisterPfSubject`). No grant was PF-shaped in a way that needed generalising.
- **The audit event type string** used by both `RegisterPfSubject` and `RegisterPfaSubject` is the
  identical `'TaxSubjectRegistered'` — not `'PfSubjectRegistered'`. Confirmed by reading
  `RegisterPfSubject.php` before writing `RegisterPfaSubject.php`: the existing name was already
  generic, so no renaming or migration of historical audit events was needed.

## One caveat found during the audit: the "financial event → tax fact" normalizer does not exist yet, for any subject type

`BLUEPRINT.md` §32 names `PfaTaxFactNormalizer` as an expected PFA-specific artifact. Investigating
what a normalizer would need to plug into: `App\Domain\Wallet\Events\DepositInterestEventRecorded`
and `App\Domain\Rental\Events\RentalAccrualRecorded` are both dispatched, both carry the docblock
"a listener there... should create a `tax_facts` row... not built here" — but grepping the whole
`app/` tree for a listener class or `TaxFact::create(`/`new TaxFact(` outside `App\Domain\TaxEngine`
itself and its own tests found **none**. No financial-event-to-`TaxFact` normalizer has been built
for **PF either**, despite PF being complete through Milestone 8.

This means there is currently no PF-specific normalizer for a PFA-specific one to risk being
coupled to, and building a *first* one under a "PFA-specific" label — before PF has one — would
itself be backwards and speculative (`docs/CODE-QUALITY.md` §2). This task does not build one, for
either subject type; it is recorded here as a genuine gap this audit surfaced, for whichever task
next wires Wallet/Rental events into `TaxFact` creation to build the listener generically from the
start (both events already carry `taxSubjectId` with no subject-type assumption baked in, so the
generic version should be straightforward — confirmed by reading both event classes in full).

## Methodology

- Every module's directory under `app/Domain/` was grepped for `SubjectType`, `PfProfile`, and the
  bare string `'PF'`/`"PF"`, then each match was opened and read for context (a docblock citation
  is not the same as a dependency).
- `deptrac.php` was read in full before any code was written, to establish the pre-existing grant
  set as a baseline `deptrac analyse` before/after comparison could be measured against.
- `App\Domain\TaxEngine`'s complete `DataTransferObjects/`, `Services/`, and `Contracts/`
  directories were read in full (not sampled) to establish that subject-type-agnosticism was a
  pre-existing property, not something this task retrofitted.
- Every finding above was verified by reading the actual file at the cited path/line, not
  reconstructed from a handoff note's summary of it.

## Sources consulted

`BLUEPRINT.md` §2.2, §32, §33, §38.B; `docs/AGENTS.md` §2, §6, §10; `docs/ARCHITECTURE.md` §2;
`docs/adr/0004-tax-engine-deterministic.md`, `docs/adr/0010-tax-engine-ports-and-adapters.md`;
`docs/handoff/011-identity-tenancy.md`, `docs/handoff/020-tax-engine.md`; every file cited above by
path, read directly.
