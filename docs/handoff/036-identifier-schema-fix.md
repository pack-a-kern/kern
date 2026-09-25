# 036 — Identifier schema fix: canonical fiscal identifier, kind-bound blind index

**Milestone:** M1/M12 follow-up   **Status:** complete
**Date:** 2026-09-08

## What was built

Two identifier-schema defects recorded by `docs/reference/pf-coupling-audit.md` (surfaced during
the PFA extension proof, `docs/handoff/034-pfa-extension.md`, but left unfixed there as out of
that task's scope) are fixed:

1. **The CNP was stored twice** (`tax_subjects.fiscal_identifier_blind_index` and
   `pf_profiles.cnp_blind_index`), and `pf_profiles`' own migration docblock falsely claimed CNP
   was "never duplicated anywhere else in the schema." This is the audit's **Finding 3**.
2. **`tax_subjects.fiscal_identifier_blind_index` was a single, unqualified uniqueness namespace**
   shared across every subject type, with no way for a lookup to tell which identifier *kind* a
   matched row actually held. This is the audit's **Finding 2**.

Both are resolved by the same decision, recorded as **ADR 0011**
(`docs/adr/0011-canonical-fiscal-identifier.md`): `tax_subjects.fiscal_identifier_ciphertext`/
`fiscal_identifier_blind_index`/`fiscal_identifier_kind` (a new column) is now the single canonical
location for **any** subject type's fiscal identifier. `pf_profiles`/`pfa_profiles` no longer store
an identifier of their own. A new `Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind` enum
(`CNP`/`CUI`) binds the identifier's kind into the value that gets hashed, so a CNP and a business
identifier that normalise to the same raw digits can never collide under one blind index.

**A note on the task's own findings numbering**: this task's brief described its work as closing
"findings 1 and 3." Reading the audit document to scope the work found that the two schema defects
actually described in the brief (a duplicated CNP blind index; one shared uniqueness namespace
across identifier kinds) are the audit's Findings **3 and 2**, not 1. Finding 1 (a
`SensitiveDataProcessor` logging-redaction gap) turned out to already be fixed in
`app/Support/Logging/SensitiveDataProcessor.php` by earlier, undocumented work with no
corresponding handoff note — verified by reading that file directly, not touched or re-tested
here (`app/Support/Logging/**` is outside this task's scope). `docs/reference/pf-coupling-audit.md`
now marks all three findings resolved, with each finding's own note explaining exactly which task
resolved it and how.

### Entry points

```php
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;

$blindIndex = $blindIndexHasher->compute(FiscalIdentifierKind::CNP->keyedValue($cnp));
$blindIndex = $blindIndexHasher->compute(FiscalIdentifierKind::CUI->keyedValue($registrationNumber));
```

`Kern\TaxRomania\Subjects\Actions\RegisterPfSubject`/`RegisterPfaSubject` both use this to compute and
store `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`/
`fiscal_identifier_kind`; duplicate detection for both is one query:
`TaxSubject::query()->where('fiscal_identifier_blind_index', $index)->exists()`.

## Files created / changed

**New:**
- `apps/api/packages/tax-romania/src/Subjects/Enums/FiscalIdentifierKind.php` — `CNP`/`CUI`, `normalize()`,
  `keyedValue()`. See its own docblock and ADR 0011 for why kind-in-hash was chosen over a
  composite `UNIQUE (kind, blind_index)` constraint.
- `docs/adr/0011-canonical-fiscal-identifier.md` — the decision, including the §5.2-vs-§6.2
  blueprint contradiction it resolves and the key-in-hash-vs-composite-unique reasoning.

**Migrations edited in place** (no production data exists yet; `docs/AGENTS.md` §7b's own
throwaway-database convention confirms nothing depends on the prior shape, so a clean schema was
judged worth more than a migration history nobody has run):
- `apps/api/database/migrations/2026_09_08_200001_create_tax_subjects_table.php` — added
  `fiscal_identifier_kind` enum column (`CNP`/`CUI`, nullable, populated alongside the
  ciphertext/blind-index pair in the same second write every `TaxSubject` registration already
  does); docblock corrected to describe the column as the single canonical location for any
  subject type's identifier, and to explain why the blind index stays single-column `UNIQUE`
  rather than a composite `(kind, blind_index)` key.
- `apps/api/database/migrations/2026_09_08_200003_create_pf_profiles_table.php` — dropped
  `cnp_ciphertext`/`cnp_blind_index`; docblock corrected (the prior claim that CNP was "never
  duplicated anywhere else" was false as shipped — it is now true, because the column that would
  have made it false no longer exists here).
- `apps/api/database/migrations/2026_09_09_200000_create_pfa_profiles_table.php` — dropped
  `registration_number_ciphertext`/`registration_number_blind_index`; docblock corrected to explain
  the identifier now lives on `tax_subjects`, tagged `FiscalIdentifierKind::CUI`.

**Models:**
- `apps/api/packages/tax-romania/src/Subjects/Models/TaxSubject.php` — added `fiscal_identifier_kind` to
  `$fillable`/`$casts` (cast to `FiscalIdentifierKind`), updated docblock.
- `apps/api/packages/tax-romania/src/Subjects/Models/PfProfile.php` — removed `cnp_ciphertext`/`cnp_blind_index`
  from `$fillable`/`$casts`/property docblock.
- `apps/api/packages/tax-romania/src/Subjects/Models/PfaProfile.php` — removed
  `registration_number_ciphertext`/`registration_number_blind_index` from
  `$fillable`/`$casts`/property docblock.

**Actions:**
- `apps/api/packages/tax-romania/src/Subjects/Actions/RegisterPfSubject.php` — blind index now computed via
  `FiscalIdentifierKind::CNP->keyedValue($input->cnp)`; duplicate check now queries `TaxSubject`
  instead of `PfProfile`; sets `fiscal_identifier_kind`; `PfProfile::create()` no longer passes
  `cnp_ciphertext`/`cnp_blind_index`.
- `apps/api/packages/tax-romania/src/Subjects/Actions/RegisterPfaSubject.php` — blind index now computed via
  `FiscalIdentifierKind::CUI->keyedValue($input->registrationNumber)`; duplicate check now queries
  `TaxSubject` (previously queried `PfaProfile`); now populates
  `tax_subjects.fiscal_identifier_ciphertext`/`fiscal_identifier_blind_index`/
  `fiscal_identifier_kind` in its second write, exactly mirroring `RegisterPfSubject`;
  `PfaProfile::create()` no longer passes `registration_number_ciphertext`/
  `registration_number_blind_index`.
- `apps/api/packages/tax-romania/src/Subjects/Exceptions/DuplicateCnpException.php`,
  `DuplicateRegistrationNumberException.php` — docblocks updated to describe the check against the
  canonical `tax_subjects` column instead of the removed profile-table columns.

**Factories:**
- `apps/api/database/factories/TaxSubjectFactory.php` —
  `withFiscalIdentifier(string $cnp)` became `withFiscalIdentifier(FiscalIdentifierKind $kind,
  string $rawValue)`. No other caller existed (confirmed by grep before changing the signature).

**Tests** (`apps/api/tests/Feature/Domain/Subjects/`), all real, all executed — see "How to
verify":
- `RawDatabasePlaintextTest.php` — removed the now-nonexistent `pf_profiles.cnp_ciphertext`
  assertion; added a schema assertion that `pf_profiles.cnp_ciphertext`/`cnp_blind_index` do not
  exist at all (the "never duplicated" guarantee is now a schema fact, not just a value
  comparison).
- `BlindIndexLookupTest.php` — rewritten: duplicate-CNP detection now exercised against
  `TaxSubject::fiscal_identifier_blind_index` (previously `PfProfile::cnp_blind_index`, which no
  longer exists). Three new tests added: a CNP and a business identifier that normalise to the
  identical digit string do not collide; the same raw value under `CNP` vs `CUI` produces
  different blind indexes; a lookup by identifier returns the correct subject and reports the
  correct `fiscal_identifier_kind` for both kinds, from two coexisting subjects.
- `RegisterPfaSubjectTest.php` — the test that previously asserted
  `tax_subjects.fiscal_identifier_*` stays `NULL` for a PFA registration now asserts the opposite:
  it is populated, tagged `FiscalIdentifierKind::CUI`, and decrypts back to the registration
  number.
- `PfaEncryptionAndCoexistenceTest.php` — the raw-plaintext test now checks
  `tax_subjects.fiscal_identifier_ciphertext` instead of the removed
  `pfa_profiles.registration_number_ciphertext`, plus a schema assertion that the removed columns
  are gone; the decrypt-round-trip test now asserts the registration number decrypts from
  `TaxSubject` instead of `PfaProfile`.

**Documentation:**
- `docs/reference/pf-coupling-audit.md` — Findings 1, 2, and 3 all marked resolved, each with its
  own note on exactly what resolved it (see "What was built" above for the Finding-1 provenance
  caveat).

**Not touched** (confirmed by grep before finishing, not merely assumed): any file under
`Kern\TaxRomania\{Identity,Wallet,Documents,Fx,Spv,Audit,Declarations,TaxKnowledge,TaxEngine,Rental}`,
`App\Http\**`, `App\Support\Logging\**`, `App\Support\KeyManagement\**`,
`App\Support\Crypto\Casts\**`/`Concerns\**`/`Contracts\**` (only `BlindIndex/**` was in scope, and
even there only a new consuming enum was added — `BlindIndexHasher`/`Normalizer` themselves are
byte-for-byte unchanged, see "Key decisions" #1), `deptrac.php`, `docs/STATUS.md`.

## Key decisions

1. **`BlindIndexHasher`/`Normalizer` were left unmodified; the kind-binding lives entirely in a
   new `Subjects`-owned enum.** `BlindIndexHasher::compute(string $normalizedValue)` is a generic
   HMAC primitive already consumed by `Identity` (email), `Wallet` (IBAN, external transaction
   id), and `Spv` (SPV username) — none of which needed or asked for a "kind" concept. Changing
   `compute()`'s signature to take a kind parameter would have forced every existing call site to
   change for no benefit to those modules, well outside this task's declared scope
   (`apps/api/packages/tax-romania/src/Subjects/**`, `apps/api/app/Support/Crypto/BlindIndex/**` — the second
   path was available to edit, not required to be edited). Instead,
   `FiscalIdentifierKind::keyedValue(string): string` produces the exact string
   `BlindIndexHasher::compute()` already accepts unchanged — `"CNP:" . Normalizer::cnp($raw)"` or
   `"CUI:" . Normalizer::text($raw)`. This is also the more scope-honest shape per
   `docs/CODE-QUALITY.md` §1: only `Subjects` currently needs kind-namespacing, so the
   kind-binding concept lives where its only consumer is, not generalised into `Support` on
   spec.
2. **Kind-in-hash over composite `UNIQUE (kind, blind_index)`** — see ADR 0011's own section for
   the full reasoning. Short version: once kind is bound into the hashed input, a composite unique
   constraint would enforce nothing a single-column unique index doesn't already guarantee, while
   adding a way for `fiscal_identifier_kind` to silently disagree with what the blind index
   actually encodes (two independently-writable columns) and still pass the constraint.
3. **`CUI` (not, say, `PFA_REGISTRATION_NUMBER`) was chosen as the second enum case's name**,
   despite `RegisterPfaSubjectInput::$registrationNumber`'s own docblock describing it as an
   unvalidated free-form string with no verified format (an ONRC-style trade-register number,
   `J40/1234/2020`, in every existing test fixture — not literally a CUI/fiscal code in the
   narrowest sense). This matches terminology `app/Support/Logging/SensitiveDataProcessor.php`
   already established independently (its `SENSITIVE_KEYS` comment: "a PFA's fiscal identifier is
   a CUI rather than a CNP, and `tax_subjects.fiscal_identifier_ciphertext` holds whichever
   applies") — that file already assumed, before this task existed, that the canonical column
   would eventually hold a PFA identifier labelled `CUI`. Using the same label avoids introducing
   a second name for the same concept across two modules. Per `docs/handoff/034-pfa-extension.md`
   Key decision #3 (unchanged by this task): no format validation is attached to either enum
   case beyond the normalisation rule (`Normalizer::cnp()` for `CNP`, `Normalizer::text()` for
   `CUI`) — the enum name is a semantic label for "this column's role," not a shape assertion.
4. **`fiscal_identifier_kind` is a plain (unencrypted) column, not part of any unique
   constraint.** Not sensitive — see `FiscalIdentifierKind`'s own docblock and ADR 0011. Adding it
   to a constraint was considered and rejected (decision/ADR section above).
5. **Migrations edited in place, not stacked.** Confirmed no environment has production data
   against the prior shape (`docs/AGENTS.md` §7b's per-task throwaway-database convention is the
   only way this schema has ever been exercised) before choosing this over a corrective migration.

## How to verify

Own database per `docs/AGENTS.md` §7b (`kern_ident`, created before starting — no other agent's
"Files currently being worked on" entry in `docs/STATUS.md` overlapped this task's scope, but a
dedicated database was used anyway per that section's own recommendation):

```sh
docker exec anaf-postgres-1 psql -U kern -d kern -c "CREATE DATABASE kern_ident OWNER kern;"
docker exec anaf-postgres-1 psql -U kern -d kern_ident -c \
  "CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS unaccent; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
```

**Baseline, confirmed before any change** (`kern-php-test:local`, the prebuilt PHP 8.5-cli image
already present from prior tasks):

```text
$ vendor/bin/pest        # against kern_ident, freshly migrated
Tests:    3 warnings, 10 skipped, 741 passed (2238 assertions)
```

Matches the task brief's stated baseline exactly.

**Migrations — both required scenarios**, against the live `kern-php-test:local` image on
`anaf_data_net`:

```text
$ php artisan migrate:fresh --force        # a used database (kern_ident, already migrated once)
  ... 60 migrations, including the three edited in this task ... DONE

$ php artisan migrate:rollback --step=3 --force && php artisan migrate --force
  2026_09_09_200000_create_pfa_profiles_table ... DONE   (rollback)
  2026_09_09_100002/100003_* ... DONE                     (rollback)
  2026_09_09_100002/100003_*, 2026_09_09_200000_* ... DONE  (re-migrate)

$ # a genuinely fresh database (kern_ident_fresh, created and dropped for this check only)
$ php artisan migrate --force
  ... all 60 migrations ... DONE
```

Both scenarios the task asked for — `migrate` on a genuinely fresh database, `migrate:fresh` on a
used one — succeed.

**Quality gates, after the change** (`kern-php-test:local`):

```text
$ vendor/bin/pint --test
  PASS ......................................................... 780 files

$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G   # level 9, no baseline
 [OK] No errors

$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 633, Allowed 325, Warnings 0, Errors 0

$ composer validate --strict && composer audit
./composer.json is valid
No security vulnerability advisories found.
```

**Full suite, run twice against `kern_ident`** (real output, both runs):

```text
$ vendor/bin/pest   # run 1
Tests:    3 warnings, 10 skipped, 744 passed (2244 assertions)
Duration: 74.10s

$ vendor/bin/pest   # run 2, same database — leak/idempotency check
Tests:    3 warnings, 10 skipped, 744 passed (2244 assertions)
Duration: 72.02s
```

Both runs identical, 0 failed — no drop from the 741-passed baseline; +3 is this task's own new
tests in `BlindIndexLookupTest.php` (cross-kind non-collision, kind-differentiated hashing,
lookup-returns-correct-kind). The 10 skipped are the pre-existing live-service tests (OpenBao,
MinIO) that self-skip without a reachable live service from this container — unrelated to this
task, unchanged from baseline. The 3 warnings are the same pre-existing live-service warnings.

Specific acceptance criteria from the task brief, each independently confirmed by a named test
above: duplicate-CNP detection via the canonical column (`RegisterPfSubjectTest`,
`BlindIndexLookupTest`); a CNP and a business identifier with the same digits do not collide
(`BlindIndexLookupTest`'s new test); lookup by identifier returns the right subject and cannot
cross-match kinds (`BlindIndexLookupTest`'s new test); PF and PFA registration both populate the
canonical columns (`RawDatabasePlaintextTest`, `RegisterPfaSubjectTest`); raw-database assertion
of no plaintext identifier anywhere (`RawDatabasePlaintextTest`, `PfaEncryptionAndCoexistenceTest`);
existing cross-tenant tests still pass (`CrossTenantIsolationTest`, unmodified, part of the 744).

## Interfaces exposed to other modules

- `Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind::CNP`/`::CUI`, `->normalize()`, `->keyedValue()`
  — any future II/IF/SRL profile action adds a new case here and populates the same three
  canonical `tax_subjects` columns; no other module change is implied.
- `Kern\TaxRomania\Subjects\Models\TaxSubject::$fiscal_identifier_kind` — new property, cast to
  `FiscalIdentifierKind`, nullable until a registration action populates it.
- `Kern\TaxRomania\Database\Factories\TaxSubjectFactory::withFiscalIdentifier(FiscalIdentifierKind, string)` — the
  sanctioned way a test gets a `TaxSubject` with a populated, decryptable identifier of either
  kind (signature changed from the PF-only `withFiscalIdentifier(string $cnp)` — no other caller
  existed, confirmed by grep).

## What is NOT done

- **`app/Support/Logging/SensitiveDataProcessor.php` was not touched or re-tested** — it already
  contains the fix Finding 1 recommended, from earlier undocumented work; this task only verified
  that by reading the file, since it is outside this task's declared scope. Whoever next touches
  that file should write the missing handoff note.
- **`database/migrations/2026_09_08_500000_create_financial_accounts_table.php`'s own docblock**
  still references `pf_profiles.cnp_blind_index` as an illustrative example of a globally-unique
  duplicate-detection index (in a comment explaining why `financial_accounts.iban_blind_index` is
  scoped per-subject instead). That column no longer exists under that name — the conceptual point
  the comment makes is still correct (a global duplicate-detection index exists elsewhere in the
  schema; it is now `tax_subjects.fiscal_identifier_blind_index`), but the literal column name is
  now stale. Not fixed here: `packages/tax-romania/src/Wallet/**` and its migrations are outside this task's
  declared scope ("Do not touch other Domain modules"). Recorded here per `docs/AGENTS.md` §10.
- No validation of `registrationNumber`'s shape was added (unchanged from
  `docs/handoff/034-pfa-extension.md`'s own explicit non-goal) — this task only changed where and
  how its blind index is computed and stored, never what shapes are accepted.
- No re-indexing/migration of historical data was needed or performed — confirmed no environment
  has data under the prior schema (`docs/AGENTS.md` §7b).

## Open questions

- Should `App\Support\Logging\SensitiveDataProcessor.php`'s missing handoff note be reconstructed
  retroactively by whoever can determine when/how that fix landed? Not attempted here — outside
  this task's scope and no reliable way to date it from the file alone.
- Should `database/migrations/2026_09_08_500000_create_financial_accounts_table.php`'s stale
  `pf_profiles.cnp_blind_index` reference be corrected to name the new canonical column? A small,
  one-line documentation fix, but outside this task's declared module scope (`packages/tax-romania/src/Wallet`).

## Sources consulted

`BLUEPRINT.md` §4.4, §5.2, §6.2, §38.B; `docs/AGENTS.md` §2, §6, §7b, §10; `docs/CODE-QUALITY.md`
§1–§2; `docs/reference/pf-coupling-audit.md` (Findings 1, 2, 3, read in full before scoping this
task); `docs/handoff/005-crypto-core.md` (`BlindIndexHasher`, `Normalizer`, the global versioned
blind-index key — read in full before deciding not to modify either class);
`docs/handoff/011-identity-tenancy.md`, `docs/handoff/034-pfa-extension.md` (read in full; this
task supersedes their Key decisions on the shared-column question). Every application file this
note cites was opened and read directly before this task's own change, not assumed from another
document's summary. `docs/adr/0011-canonical-fiscal-identifier.md` — the decision this task
implements.
