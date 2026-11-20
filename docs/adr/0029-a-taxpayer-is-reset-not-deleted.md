# ADR 0029 — A taxpayer is reset to an anonymous husk, not deleted, and its CNP can be re-added

**Status:** accepted, 2026-11-20

## Context

The account screen needs a "delete everything" for a taxpayer — clear its accounts, deposits,
investments, documents, imports, rentals and computed tax figures so the person starts clean. Three
facts from the existing schema shape what "delete" can actually mean.

**1. The `tax_subjects` row and its audit trail cannot be removed.** `audit_events` and
`audit_chain_heads` reference `tax_subjects` `ON DELETE RESTRICT`, and `audit_events` carries a
`BEFORE DELETE/UPDATE` trigger (`audit_events_deny_mutation`, see
`packages/audit/database/migrations/..._create_audit_events_table.php`) that raises on *any* mutation
of an audit row — the append-only, tamper-evident guarantee of `BLUEPRINT.md` §4.8. A subject that
has ever acted therefore has an audit chain that can never be deleted, which in turn pins the subject
row. Every other table that references `tax_subjects` is `CASCADE` (the data) or a deletable child
(`declarations`, `tax_assessments`, `tax_facts`), so only the audit pair blocks removal.

**2. Deleting a document, SPV connection or import is not a bare row delete.** Each owns external
state — encrypted blobs in object storage, external session state, uploaded files — cleaned up only
by `DeleteDocument` / `DeleteSpvConnection` / `DeleteStatementImport`, not by the model's deletion.

**3. The CNP is a single `UNIQUE` hash.** `tax_subjects.fiscal_identifier_blind_index` is unique
(ADR 0011), and `RegisterPfSubject` refuses a CNP already present. A reset that left the hash on a
row the person can no longer see would trap that CNP forever: invisible *and* un-re-addable.

The per-account delete action (`DeleteFinancialAccount`) is the opposite of what a reset wants — it
*refuses* to drop an account with history, to protect tax records, and never drops General. A reset
is a deliberate force-wipe, not that.

## Decision

**"Delete a taxpayer" is a reset, implemented by `ResetTaxSubject`:**

- Everything the subject owns is removed. External-artifact domains go through their own delete
  actions (`DeleteDocument`, `DeleteSpvConnection`, `DeleteStatementImport`) so blobs and external
  state are torn down; the rest is deleted by `tax_subject_id`, the wallet subtree cascading from
  `financial_accounts`. The list of tables is hand-maintained in the action.
- Every membership is **revoked** (not deleted — revocation is history), so the taxpayer leaves the
  user's list.
- The subject row is **scrubbed to an anonymous husk**: name and the recoverable
  `fiscal_identifier_ciphertext` nulled, status set `CLOSED`. The one-way
  `fiscal_identifier_blind_index` and the subject's DEK are **kept**.
- One `TaxSubjectReset` audit event is appended. The row and its chain remain as immutable residue.

**Re-registering the CNP reconnects the husk, for anyone.** `RegisterPfSubject` (and, by the same
pattern, each other `Register*Subject`) treats a CNP that matches an **unowned** subject — a husk
with no membership in force — as *available*: it re-stores the identity on that same row, re-grants
ownership and reactivates it, rather than creating a new row (which the kept `UNIQUE` hash would not
even allow). A CNP still **actively owned** is refused exactly as before. The ownership decision is
taken under a `lockForUpdate`, so two registrations racing for the same freed CNP cannot both
attach. Reconnection is **not** restricted to the previous owner — registration is first-come for
any CNP and the husk holds no data to protect — matching the installation's existing trust model.
`FindSubjectByCnp` reports an unowned husk as available too, so the pre-registration CNP check
agrees and reveals nothing of the past.

## Consequences

- **A reset is not an erasure.** The subject row and its audit chain survive, by design — the chain
  is the tamper-evident record §4.8 requires. What survives carries no name, no readable identifier
  and no data: an anonymous anchor. This is the honest answer to "make the taxpayer vanish" under an
  append-only audit log, and it is stated plainly to the user.
- **The audit chain spans every owner of a CNP.** Because the husk is reused on re-add, one chain
  accumulates across resets and reattaches; each event still records its own `actor_id`, and the
  cross-subject audit screen that shows it is gated by `audit.view` (admin only). A subject's own
  members see only narrow per-resource history, and a reset removed those resources, so a re-attached
  owner starts blank.
- **A reset CNP that is never re-added leaves one dormant husk.** Reuse on re-add keeps this bounded
  to at most one husk per distinct CNP, never a growing pile per reset.
- **The wipe list is a maintenance point.** A new table that references `tax_subjects` must be added
  to `ResetTaxSubject` (or routed through its own delete action) or the reset will leave its rows.
  There is no automatic discovery: the test lane is SQLite, with no portable FK introspection. This
  is called out in the action's docblock.
- **Reset is owner-only and runs inside the `tax-subject` tenant binding**, so its tenant-scoped
  queries resolve to exactly the subject being reset; a non-owner member may read a taxpayer but not
  erase it.
- Reattach is wired for both registration paths today (`RegisterPfSubject` for a CNP,
  `RegisterPfaSubject` for a CUI); any future type follows the identical branch, since the reset and
  the kept kind-bound hash are already type-agnostic.

## Sources consulted

`BLUEPRINT.md` §4.8 (append-only hash chain); `packages/audit/database/migrations` (the
`audit_events` trigger and `audit_chain_heads` per-subject chain key); ADR 0011 (the canonical,
`UNIQUE`, kind-bound fiscal identifier); ADR 0002 (envelope encryption / the subject DEK);
`DeleteFinancialAccount` (the guarded per-account delete a reset deliberately is not);
`DeleteDocument` / `DeleteSpvConnection` / `DeleteStatementImport` (external-state cleanup reused by
the reset).
