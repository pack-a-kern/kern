# ADR 0011 — `tax_subjects` is the single canonical location for a subject's fiscal identifier

**Status:** accepted, 2026-09-08

## Context

Two schema defects, both flagged by `docs/reference/pf-coupling-audit.md` during the PFA
extension proof (`docs/handoff/034-pfa-extension.md`) but left unfixed there as out of that task's
scope:

**1. The CNP was stored twice.** `App\Domain\Subjects\Actions\RegisterPfSubject` wrote the same
CNP blind index to both `tax_subjects.fiscal_identifier_blind_index` and
`pf_profiles.cnp_blind_index` — two independent `UNIQUE` constraints enforcing the identical
invariant, and two places a future key-rotation batch job would have to touch. The `pf_profiles`
migration's own docblock claimed CNP was "never duplicated anywhere else in the schema"
(`BLUEPRINT.md` §6.2: "Nu duplica CNP în alte tabele") — a claim that was false as shipped.

This is not a simple oversight to patch; it is a genuine contradiction inside `BLUEPRINT.md` itself.
§5.2 specifies `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index` as part
of the generic tenant-boundary table. §6.2 separately specifies `pf_profiles.cnp_ciphertext`/
`cnp_blind_index` *and* says CNP must never be duplicated elsewhere. Both sections cannot be
followed literally at once — whichever table is populated, the other section's schema still exists
and is either unused (dead code, forbidden by `docs/CODE-QUALITY.md` §2) or duplicated (forbidden
by §6.2's own words). Someone had to pick one. This ADR is that decision.

**2. `tax_subjects.fiscal_identifier_blind_index` was a single, unqualified uniqueness
namespace.** The column is `UNIQUE` with no per-type scoping. `docs/reference/pf-coupling-audit.md`
Finding 2 identified the risk precisely: if a future PFA registration populated the same column
with a business identifier (the "obvious" thing to do, mirroring PF), a CNP-shaped value and a
business-identifier-shaped value that happened to normalise to the same raw string would collide
under one unqualified `HMAC(key, value)` — blocking a legitimate registration — and, independent of
collision probability, a lookup that *did* match the column could not say what kind of identifier
it had actually matched. `docs/handoff/034-pfa-extension.md`'s `RegisterPfaSubject` avoided the risk
by simply not populating the shared column at all, which sidesteps the collision but leaves the
column effectively PF-only in practice — an asymmetry the audit itself flagged as needing a
deliberate resolution, not indefinite avoidance.

## Decision

**`tax_subjects.fiscal_identifier_ciphertext` / `fiscal_identifier_blind_index` /
`fiscal_identifier_kind` is the single canonical location for a subject's fiscal identifier,
whatever its type.** `App\Domain\Subjects\Models\PfProfile` and `PfaProfile` no longer store an
identifier of their own — `pf_profiles` dropped `cnp_ciphertext`/`cnp_blind_index`,
`pfa_profiles` dropped `registration_number_ciphertext`/`registration_number_blind_index`. Both
tables now hold only genuinely type-specific data: name/birth date/residency for PF, trade
name/CAEN code for PFA — neither of which is itself "the fiscal identifier."

This resolves the §5.2-vs-§6.2 contradiction by choosing §5.2's shape: the generic slot on the
tenant-boundary table, not a per-type profile column. The reasoning: `BLUEPRINT.md` §38.B's own
definition of architectural success is "add PFA/SRL without rewriting Identity/Wallet/Documents/
Fx/Spv/security" — that requires *some* mechanism for "find the subject whose identifier is X"
that works without the caller already knowing the subject's type. A per-type profile column
cannot do that (a lookup has to check every profile table, once per type, forever). A single
generic column on the tenant boundary itself can, provided it does not carry defect 2's
collision risk — which the second half of this decision removes.

### Closing the uniqueness-namespace risk: kind bound into the hashed input

A new enum, `App\Domain\Subjects\Enums\FiscalIdentifierKind` (`CNP`, `CUI`), makes "what kind of
identifier is this" an explicit, typed concept instead of an assumption baked into which table a
caller happened to write to. Its `keyedValue(string $rawValue): string` method returns
`"{$this->value}:{normalize($rawValue)}"` — normalising with `Normalizer::cnp()` for `CNP` (a
verified fact about CNP's fixed 13-digit shape) and the generic `Normalizer::text()` for `CUI`
(the same choice `docs/handoff/034-pfa-extension.md` Key decision #3 already made for a PFA
registration number — no verified single format rule exists for a business identifier without an
unverified assumption, `docs/AGENTS.md` §2). This keyed string, not the bare normalised value, is
what gets hashed into the blind index.

Concretely: `App\Domain\Subjects\Actions\RegisterPfSubject` computes
`FiscalIdentifierKind::CNP->keyedValue($cnp)`; `RegisterPfaSubject` computes
`FiscalIdentifierKind::CUI->keyedValue($registrationNumber)`. Because `CNP`/`CUI` are a fixed,
closed set of literal prefixes that never appear as user input, the two hashed inputs can never
coincide for two different kinds, regardless of what the underlying raw values are — a CNP and a
business identifier that happen to normalise to the identical digit string still hash to different
blind indexes. Duplicate detection for both kinds shares one query
(`TaxSubject::query()->where('fiscal_identifier_blind_index', $index)->exists()`) with no
possibility of cross-kind false positives.

`fiscal_identifier_kind` is stored as a third, plain (non-encrypted) column alongside the
ciphertext/blind-index pair. It is not sensitive — knowing that a subject's identifier *is a CNP*
carries no more information than already knowing `tax_subjects.type = PF` — and it is what lets a
reader who already has a matched row answer "which kind of identifier is this" without decrypting
anything or guessing from shape.

### Key-in-hash vs. composite `UNIQUE (kind, blind_index)`

Both would prevent cross-kind collisions. This ADR chooses key-in-hash, not a composite unique
constraint, for two reasons:

1. **The composite key would be redundant, not merely equivalent.** Once the kind is bound into
   the hashed input, two different `(kind, raw value)` pairs already cannot produce equal
   `fiscal_identifier_blind_index` values (barring an HMAC-SHA-256 collision — cryptographically
   negligible, and no weaker an assumption than every other blind index in this codebase already
   makes). A composite `UNIQUE (kind, blind_index)` would do the identical job with an extra
   column in the index, for no additional guarantee.
2. **A composite key can silently drift out of sync with what the hash actually encodes.** With a
   composite constraint, a future bug that writes `kind = CUI` alongside a blind index actually
   computed with `FiscalIdentifierKind::CNP->keyedValue(...)` (a copy-paste mistake, say) would
   pass the constraint uncaught — the two columns are independently writable, and the database has
   no way to know they must agree. With kind-in-hash, the blind index itself is only ever
   reproducible by calling `keyedValue()` on the *correct* kind, so `fiscal_identifier_kind`
   already has no independent freedom to disagree with what produced the index next to it, and
   the single-column `UNIQUE` remains the load-bearing constraint it already was before this ADR.

`fiscal_identifier_blind_index` therefore keeps its existing single-column `UNIQUE` index
unchanged; `fiscal_identifier_kind` is not part of any constraint.

## Consequences

- `App\Domain\Subjects\Models\PfProfile`/`PfaProfile` are smaller and hold only what their names
  promise: type-specific profile data, never a copy of the tenant's fiscal identifier.
- `RegisterPfaSubject` now populates `tax_subjects.fiscal_identifier_*` exactly the way
  `RegisterPfSubject` does — the asymmetry `docs/reference/pf-coupling-audit.md` Finding 2 recorded
  as needing a deliberate resolution is gone; the column is genuinely generic, not PF-only in
  practice.
- A future II/IF/SRL profile follows the same pattern: whatever identifier that type uses gets a
  new `FiscalIdentifierKind` case and populates the same three canonical columns; the profile
  table holds only what is specific to that type. No new per-type uniqueness table, no new
  `deptrac.php` grant, no change to `TenantContext`/`ScopedToTaxSubject`/`Identity`/`Wallet`/
  `Documents`/`Fx`/`Spv`/the encryption or audit core — the exact shape `BLUEPRINT.md` §38.B asks
  extensibility to take.
- The three migrations this ADR touches (`tax_subjects`, `pf_profiles`, `pfa_profiles`) were edited
  in place rather than stacked with corrective migrations, since none carries production data yet
  (`docs/AGENTS.md` §7b's throwaway-database convention confirms no environment depends on the
  prior shape) — a clean schema was judged worth more than a migration history nobody has run.

## Sources consulted

`BLUEPRINT.md` §4.4 (blind index formula), §5.2, §6.2 (the two contradicting schema sections), §38.B
(extensibility success criteria); `docs/AGENTS.md` §2 (no unverified assumptions), §6 (module
boundaries), §7b; `docs/CODE-QUALITY.md` §1 (duplicated invariants), §2 (no dead code);
`docs/reference/pf-coupling-audit.md` Findings 2 and 3; `docs/handoff/005-crypto-core.md`
(`BlindIndexHasher`, `Normalizer`, the global versioned index key); `docs/handoff/011-identity-
tenancy.md` (original `tax_subjects`/`pf_profiles` design); `docs/handoff/034-pfa-extension.md`
(the PFA extension proof and its own Key decisions #2/#3, which this ADR supersedes).
