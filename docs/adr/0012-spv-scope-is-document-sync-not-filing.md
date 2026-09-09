# ADR 0012 — SPV scope is document sync, not declaration filing

**Status:** accepted, 2026-09-08
**Supersedes part of:** `BLUEPRINT.md` §13 / §26 Milestone 10

## Context
`BLUEPRINT.md` §13 and Milestone 10 specify SPV automation that both **syncs the taxpayer's inbox**
and **submits D212**. The `apps/spv-browser` worker therefore exposed
`POST /v1/spv/sessions/{id}/actions/submit-d212` (returning `501 not_implemented`).

The product owner stated the actual requirement on 2026-09-08: *"i said to get user documents from
anaf, synced them here in our app"* — and explicitly did **not** ask for filing. The filing
capability came from the blueprint, not from the product.

## Decision
**SPV automation is scoped to reading: authenticate, list inbox messages, download them into our
encrypted document store.** Declaration filing is removed.

The `submit-d212` route is **deleted**, not left as a stub. A route that answers `501` still
advertises a capability and invites something to be wired to it later; a route that does not exist
answers `404`, and a test asserts that 404 explicitly so its absence is a checked property rather
than an accident.

## Consequences

**Good**
- Less attack surface on the highest-risk component in the product. A browser-automation service
  holding a taxpayer's ANAF credentials should be able to do the fewest things possible, and
  "submit a legal document to the tax authority on someone's behalf" is the single most
  consequential action it could have.
- Removes the compliance question §13.3 flagged — automated *filing* raises questions about ANAF's
  terms of use and about who is liable for a wrongly-filed return. Reading one's own documents does
  not carry the same weight.
- Milestone 10 becomes achievable: it now depends only on observing the login and inbox pages,
  not on the unknown D212 artifact format (`STATUS.md` blocked item 2).

**Bad / accepted**
- A user must still file their D212 themselves through the portal. The product prepares the
  declaration; it does not submit it.
- If filing is wanted later it must be re-added deliberately, with its own threat-model review per
  §28 rule 20 — which is the point of removing it rather than leaving a stub.

**Unaffected**
- `Domain/Declarations` (Milestone 9) is untouched: D212 versioning, validation, the immutable
  snapshot and the rectificative lifecycle all remain. **Generating and validating** a declaration
  stays in scope; only **transmitting** it via automation is removed.

## Alternatives considered
- *Keep the 501 stub.* Rejected: it documents an intent that is no longer the product's, and a stub
  is the thing people wire to.
- *Keep it behind a feature flag.* Rejected — §19.1 forbids dead code and speculative flags, and a
  flag guarding an unimplemented action guards nothing.

## References
`BLUEPRINT.md` §13, §13.3, §26 (M10), §28 rule 20, §19.1 · `docs/reference/anaf-spv-integration.md` ·
`docs/handoff/032-spv-automation.md`
