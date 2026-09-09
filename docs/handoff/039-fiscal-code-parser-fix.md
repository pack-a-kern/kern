# 039 — Fiscal Code parser fix

**Milestone:** M3 (blocks M4)   **Status:** complete
**Date:** 2026-09-08

## What was built

Fixed the three measured defects in `App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser`
documented in `docs/reference/fiscal-code-parse-defects.md`, which blocked ingesting the real
Codul fiscal (Legea 227/2015, `legislatie.just.ro/Public/DetaliiDocument/171282`, 7.6 MB) — the
root cause of Milestone 4 being blocked. All three were genuine parser bugs, confirmed by reading
the real HTML (never guessed), and are now fixed with evidence-backed, narrowly-scoped code:

1. **Letters collapsing onto the article** (losing their alineat) — fixed by
   `groupOrphanContinuations()`, a DOM-order structural repair for a real markup defect: an
   editorial amendment note inside an `ALINEAT`'s body causes the site's own HTML to close that
   `ALINEAT`'s tag before the lettered items its own text promised.
2. **Orphan letters with no article at all** — these were never a parser bug in the way #1 is:
   they are real content inside `S_ANX` (Anexă/Annex) containers, a structural level the parser
   never modelled (`SectionType::ANEXA`, new). Annex numbering restarts per `Titlu`, so annex
   citations are prefixed with their real, verbatim enclosing `Titlu`/`Capitol`/`Secțiune` text.
3. **233 letters silently dropped** — root cause was **not** `S_LIT_TTL`/`S_LIT_BDY` recognition
   (always correct); it was that the original parser only searched a body's *direct* children for
   nested containers, and real content (e.g. Art. 3's fiscal principles) nests a `LITERA` *inside*
   a plain `S_PAR` wrapper. Fixed by `collectBodyContent()`'s recursive descent — a generic
   container-recursion fix, not `LITERA`-specific (it also fixed `PUNCT`'s undocumented −89 gap,
   630→631, as a side effect).

Fixing #3 surfaced a **fourth**, previously-invisible problem: with dropped letters now found,
**4 citation labels turned out to be genuinely ambiguous in the source itself** (not a parser bug —
see "Key decisions" #4). These are pruned (first occurrence kept, later occurrences dropped) and
reported via a new `ParsedLegislationDocument::$ambiguousCitationLabels` field, never silently
resolved or suffixed into uniqueness.

**Net result, measured against the real 7.6 MB document:**

| | Before | After | Source markers |
|---|---|---|---|
| ARTICOL | 645 | 645 | 645 (`S_ART`) — exact, unchanged |
| ALINEAT | 3 015 | 3 013 | 3 019 (`S_ALN`) — gap of 6, **fully explained**, see below |
| LITERA | 2 501 | 2 731 | 2 734 (`S_LIT`) — gap of 3, **fully explained**, see below |
| PUNCT | 542 | **631** | 631 (`S_PCT`) — now **exact** (was an unexplained −89) |
| ANEXA | 0 (not modelled) | 9 | 9 (`S_ANX`) — new, exact |
| Duplicate citation labels | **37** | **0** | — |

## Files created / changed

- `app/Domain/TaxKnowledge/Enums/SectionType.php` — added `ANEXA` case, with a docblock
  explaining why (unlike `Titlu`/`Capitol`/`Secțiune`, an annex genuinely carries its own citable
  `ALINEAT`/`LITERA`/`PUNCT` content).
- `app/Domain/TaxKnowledge/Parsing/LegislatieJustRoHtmlParser.php` — the fix, in full:
  - `CONTAINER_CLASSES` gains `'S_ANX' => SectionType::ANEXA`.
  - New `TITLE_WRAPPER_CLASSES` constant + `ancestorWrapperContext()` — walks a container's
    ancestors collecting real `S_TTL`/`S_CAP`/`S_SEC` `_TTL` label text (verbatim, never invented)
    to disambiguate an annex's per-`Titlu` numbering.
  - `parseTopLevelSections()` now passes that context as an `ANEXA`'s "parent citation" (ARTICOL
    is unaffected — its numbering is already unique document-wide, unchanged behaviour).
  - `buildCitationLabel()` gains an `ANEXA` case.
  - `splitBodyContent()`/new `collectBodyContent()` — recursive descent (Defect 3 fix), replacing
    the old direct-children-only scan. Full reasoning and the exact real HTML example (Art. 3) is
    in the method's docblock.
  - New `parseContainer(..., array $extraDirectChildren = [])` parameter + new
    `groupOrphanContinuations()` (Defect 1 fix) + new `sectionTypeOf()` helper (avoids a nullable
    array-key PHPStan violation cleanly, per `docs/CODE-QUALITY.md` §4 "no catch-all that
    swallows").
  - `parse()` now runs a final `pruneAmbiguousCitations()` pass (the 4th problem, "Key decisions"
    below) and returns the pruned tree plus the new `ambiguousCitationLabels` list.
  - `VERSION` bumped `1.0.0` → `1.1.0` (parser behaviour changed materially — anything already
    parsed under `1.0.0` should be re-parsed via `ParseLegislationDocumentVersion`, unchanged,
    not modified by this task).
  - Class docblock rewritten to reflect all of the above; the previous "S_PCT unverified" note is
    now "confirmed — 631 real occurrences".
- `app/Domain/TaxKnowledge/Parsing/OrphanContinuationGroup.php` (**new**) — small, deliberately
  mutable internal working DTO for `groupOrphanContinuations()` only (never returned from
  `parse()`); a real `readonly` `ParsedSection` can't have a child list appended after
  construction, and grouping needs to accumulate continuation elements before the final immutable
  tree is built.
- `app/Domain/TaxKnowledge/Parsing/ParsedLegislationDocument.php` — added
  `array $ambiguousCitationLabels = []`, documented in full (why it exists, the two real evidenced
  cases, what a caller must do with it).
- `tests/Fixtures/TaxKnowledge/Legislatie/codul-fiscal-extract-2026-09-08.html` (**new**) — a
  **real** extract (72 KB), not synthetic: every `<span>` subtree whose id is a real
  `legislatie.just.ro` id (`id_artNNN`/`id_litNNN`/`id_pctNNN`/`id_anxNNN`/`id_ntaNNN`) is copied
  verbatim byte-for-byte via `DOMDocument::saveHTML()` on the exact node from the real 7.6 MB
  document — never retyped. Several articles are trimmed to just the alineat(e) relevant to one
  measured defect; the file's own leading HTML comment documents exactly what is verbatim vs.
  reconstructed-shell (and the two spots using an explicitly-labelled `SYNTHETIC placeholder text`
  string for a trimmed-away real paragraph). Source URL and retrieval date are in that comment.
- `tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserFiscalCodeExtractTest.php` (**new**) —
  9 pure unit tests (no DB/HTTP) against the fixture above: exact section counts, zero duplicate
  labels, the exact 4 reported ambiguous labels, Art. 3's no-alineat letters, Art. 40^3's
  reattached orphan group, Art. 316's nested `PUNCT`-in-`LITERA`, the per-`Titlu` annex label, and
  both `S_NTA`-quoting-another-act exclusions. This is the regression fixture — the three original
  defects were invisible against the Constitution and only ever showed up against the real Fiscal
  Code, so a synthetic-only test suite would never catch a regression here again.
- `docs/reference/fiscal-code-parse-defects.md` — resolved numbers, exact evidenced explanation for
  every remaining gap, added below the original measured section (kept, not deleted, per
  `docs/AGENTS.md` rule 17's spirit — this is a reference doc, not a raw source, but the same
  "never overwrite evidence, only add to it" discipline applies).

## Key decisions

1. **`ANEXA` is a new, permanent `SectionType`, not a workaround.** `BLUEPRINT.md` §6.8 does not
   literally enumerate `SectionType`'s allowed values (that enumeration lives in this module's own
   prior docblock, from the original M3 task's scope decision, not the blueprint itself) — adding
   a 5th case that reflects a genuinely-discovered, genuinely-citable structural level is
   extending the model correctly, not violating it. `source_sections.section_type` is a plain
   `string` column (`database/migrations/..._create_source_sections_table.php`), so this needed no
   migration.
2. **The orphan-continuation reattachment rule is deliberately narrow: `LITERA` continuing
   `ALINEAT` only.** An earlier version of this fix also matched `PUNCT` continuing
   `ALINEAT`/`PUNCT`, reasoning by analogy to `LITERA`'s evidenced pattern rather than from
   evidence of its own. That broke a *correct* structure — Art. 316 alin. (1) lit. a) legitimately
   nests two independent `PUNCT` siblings (`pct. 1.`, `pct. 2.`) — nesting the second inside the
   first. The real-extract regression test caught this before it shipped (a `toHaveCount(2)`
   assertion failed with `1`). Every reattachment case this parser now performs traces to a
   specific, quoted, real HTML example — see `LegislatieJustRoHtmlParser::groupOrphanContinuations()`'s
   docblock and `docs/reference/fiscal-code-parse-defects.md` "Defect 1".
3. **Annex citations are prefixed with real, verbatim `Titlu`/`Capitol`/`Secțiune` text, not a
   fabricated qualifier.** The real document has three separate "Anexa nr. 1"s (one per `Titlu`);
   without a qualifier they'd collide exactly like the letters they contain did. `Titlu`/`Capitol`/
   `Secțiune` remain **not** modelled as their own `SectionType` rows (unchanged decision from
   Milestone 3 — they carry no citable text of their own), but reading their `_TTL` label text
   purely to disambiguate a sibling citation is not the same claim as citing them as provisions in
   their own right.
4. **The 4 genuinely-ambiguous citations are pruned and reported, never resolved by guessing or by
   a counter suffix** — this was an explicit hard constraint in this task's brief, and the right
   call independent of that: `Art. 168 alin. (9^2)` truly is a typo in the site's own `S_ALN_TTL`
   (its own amendment note says `(9^3)`); `Art. 342 alin. (1^3)` truly is the identical paragraph,
   amendment note included, rendered twice; `Art. 16 alin. (5^1) lit. a)/b)` truly is two
   independent enumerations sharing one alineat with no distinguishing number in the source. None
   of the three can be disambiguated without either fabricating a number the source doesn't carry
   or free-text-parsing Romanian prose to infer intent — both forbidden by `docs/AGENTS.md` §2. The
   scope for this task (`apps/api/app/Domain/TaxKnowledge/Parsing/**` only, not `Services/**` or
   `DataTransferObjects/**` outside `Parsing/`) meant the "don't persist twice" logic had to live in
   the parser itself, not in `LegislationSectionPersister` (unmodified) — `pruneAmbiguousCitations()`
   runs a pre-order walk that exactly matches the persister's own insertion order (parent, then
   children in order, then next sibling), so "first occurrence kept" here means the same row is
   kept there.
5. **`ambiguousCitationLabels` is not yet surfaced past `ParsedLegislationDocument`.** Wiring it
   into `LegislationParsingResult`/the `taxos:legislation:ingest` CLI output (so a human reviewer
   actually sees it without reading code) is real, valuable follow-up work, explicitly out of this
   task's scope (`Services/**`, `DataTransferObjects/**` outside `Parsing/`, and the CLI command
   were not in the stated scope). Flagged under "What is NOT done".
6. **No new Composer dependency.** Everything above uses the same `DOMDocument`/`DOMXPath` this
   module already depended on.

## How to verify

Ran inside the already-running `anaf-app-1` container (PHP 8.5.10, real vendored `apps/api/vendor/`)
against a dedicated database, per `docs/AGENTS.md` §7b (another agent's work was in flight on
`apps/api/app/Support/Crypto/**`/`apps/api/app/Domain/Audit/**` per `docs/STATUS.md`, unrelated to
this task but reason enough not to share a database):

```
$ docker exec anaf-postgres-1 psql -U taxos -d taxos -c "CREATE DATABASE taxos_fiscalcodeparser;"
CREATE DATABASE
```

**Pint:**
```
$ vendor/bin/pint --test app/Domain/TaxKnowledge/Parsing app/Domain/TaxKnowledge/Enums/SectionType.php \
    tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserTest.php \
    tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserFiscalCodeExtractTest.php
  PASS ........................................................... 7 files
```

**PHPStan (level 9, no baseline):**
```
$ php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G app/Domain/TaxKnowledge/Parsing \
    tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserFiscalCodeExtractTest.php \
    tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserTest.php
 [OK] No errors
```

**Deptrac:**
```
$ vendor/bin/deptrac analyse --config-file=deptrac.php
  Violations 0, Skipped violations 0, Uncovered 633, Allowed 325, Warnings 0, Errors 0
```

**Pest, real Postgres, TaxKnowledge module, run twice (leak check):**
```
$ DB_CONNECTION=pgsql DB_HOST=postgres DB_DATABASE=taxos_fiscalcodeparser DB_USERNAME=taxos \
    DB_PASSWORD=<redacted> vendor/bin/pest tests/Unit/Domain/TaxKnowledge tests/Feature/Domain/TaxKnowledge
  Tests: 1 skipped, 126 passed (343 assertions)     [run 1]
  Tests: 1 skipped, 126 passed (343 assertions)     [run 2, identical]
```
(the 1 skipped test is `LegislatieJustRoLiveFetchTest`, opt-in only via `LEGISLATIE_LIVE_TEST=1` —
unaffected by this task, not run, per "do not re-download the document" instruction.)

**Pest, real Postgres, full suite** (`tests/Unit tests/Feature`):
```
Tests: 48 failed, 3 warnings, 10 skipped, 704 passed (2014 assertions)
```
All 48 failures are in `Tests\Feature\Domain\Identity\*`, `Tests\Feature\Domain\Documents\*`, and
`Tests\Feature\Domain\Subjects\RegisterPfSubjectControllerTest` — every one a `419` (CSRF/session)
status mismatch, e.g. `Expected response status code [201] but received 419`. **Zero of these
touch `Domain/TaxKnowledge`**, and this task changed nothing under `Domain/Identity`,
`Domain/Documents`, `Domain/Subjects`, or `Support/**`. Per `docs/AGENTS.md` §7b ("when you see
failures in a module you did not touch, suspect this before you suspect the code") and given
`docs/STATUS.md` shows concurrent work in flight on `apps/api/app/Support/Crypto/**` (which
session/cookie encryption depends on) at the time of this run — this is a pre-existing or
concurrent-work artifact, not caused by this task. Not fixed here (out of scope); recorded here per
`docs/AGENTS.md` §10 rather than silently touched, since this task's explicit instruction was not
to edit `docs/STATUS.md`.

**`composer validate`/`composer audit`**: not run — no `composer` binary in this container (same
situation `docs/handoff/018-legislation-ingestion.md` and `012`/`014` recorded), and no dependency
changed, so there is nothing new to validate/audit.

**The real, end-to-end ingestion run against the real Fiscal Code** — the actual thing this task
had to prove. Since `SourceIngestionService::ingest()` performs a live HTTP fetch and the task
explicitly says "use the already-downloaded file, do not re-download", this reproduces every step
of the real pipeline *except* the network call: reads the real 7.6 MB `cf.html` already on disk,
stores it through the real, unmodified `RawSourceObjectStore` (content-addressed, same code path
`SourceIngestionService` itself uses), creates real `source_documents`/`source_document_versions`
rows the same way that service does, parses with the real, fixed
`LegislatieJustRoHtmlParser::parse()`, and persists through the real, unmodified
`LegislationSectionPersister::persist()` — the exact class whose `source_sections_citation_unique`
insert previously failed with `SQLSTATE[23505]`:

```
Loaded real cf.html: 7625617 bytes
Stored raw object: sources/legislatie/11d1f36b4af3670a2704b72beb55a8d12e7b1e386c6e910455f5179ba4f7858c
  sha256=11d1f36b4af3670a2704b72beb55a8d12e7b1e386c6e910455f5179ba4f7858c
SourceDocument id: 01a080a2-7d55-71c8-8dd7-f4abeb698127
SourceDocumentVersion id: 01a080a2-7d57-722e-a89f-d3ff70e9821d
Parsed. Top-level sections: 654

=== PERSISTED SUCCESSFULLY ===
sourceDocumentVersionId: 01a080a2-7d57-722e-a89f-d3ff70e9821d
sectionCount: 7029
status: REQUIRES_REVIEW
publicationDate: 2015-09-10

DB counts by section_type:
  ALINEAT: 3013
  ANEXA: 9
  ARTICOL: 645
  LITERA: 2731
  PUNCT: 631

Total rows: 7029, distinct citation_label: 7029
ZERO DUPLICATES CONFIRMED IN DB
```

`publicationDate: 2015-09-10` is `RomanianDateParser` correctly reading the real "MONITORUL
OFICIAL" line for Legea 227/2015 — itself unmodified by this task, working correctly against the
real document. `status: REQUIRES_REVIEW` is the correct, unchanged, honest Milestone 3 behaviour
(no effective date was set — see `docs/handoff/018-legislation-ingestion.md` "The temporal model");
not a defect, not this task's concern.

This test run wrote one real object to the shared MinIO instance, at the path shown above
(content-addressed, harmless, reusable — it is literally the real public Codul fiscal HTML, not
sensitive), and created two rows in the throwaway `taxos_fiscalcodeparser` database, which was
dropped afterward:
```
$ docker exec anaf-postgres-1 psql -U taxos -d taxos -c "DROP DATABASE taxos_fiscalcodeparser;"
DROP DATABASE
```

**Spot-checked citations against the actual text** (so a human can verify the parse, not just
trust a count — real text, verified against the extracts above and the original 7.6 MB file):

| Citation | Real text (excerpt) |
|---|---|
| `Art. 3` | "Principiile fiscalității" / "Impozitele și taxele reglementate de prezentul cod se bazează pe următoarele principii:" |
| `Art. 3 lit. a)` | "neutralitatea măsurilor fiscale în raport cu diferitele categorii de investitori și capitaluri..." |
| `Art. 40^3 alin. (4)` | "Prin derogare de la prevederile art. 184 alin. (1) din Codul de procedură fiscală... dacă se află în oricare dintre următoarele situații:" |
| `Art. 40^3 alin. (4) lit. a)` | "transferă active de la sediul central din România la sediul său permanent dintr-un alt stat membru..." |
| `Art. 40^3 alin. (4) lit. d)` | "transferă activitatea economică desfășurată în România printr-un sediu permanent către un alt stat membru..." |
| `Art. 316 alin. (1) lit. a)` | "înainte de realizarea unor astfel de operațiuni, în următoarele cazuri:" (with nested `pct. 1.`/`pct. 2.`) |
| `Art. 168 alin. (9^1)` | "Abrogat." (repealed by OUG 79/2017) |
| `Art. 168 alin. (9^2)` | "Abrogat." — kept occurrence; the second, colliding "(9^2)" (really "(9^3)" per its own note) is in `ambiguousCitationLabels`, not persisted twice |
| `Art. 342 alin. (1^3)` | "În situația în care doi sau mai mulți mici producători independenți cooperează..." |
| `Titlul II Anexa nr. 1` | "LISTA SOCIETĂȚILOR la care se face referire la art. 24 alin. (1) lit. b) pct. 1..." (28 lettered items) |
| `Titlul VII Capitolul XV Anexa` | the one real annex with no "nr. N", correctly disambiguated by its real enclosing `Titlu`/`Capitol` |
| `Titlul VIII Anexa nr. 2` | excise-duty product table ("Nr. crt. / Denumirea produsului / U.M / Acciza...") |

## Interfaces exposed to other modules

```php
use App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser;

$result = (new LegislatieJustRoHtmlParser())->parse($html);
// $result->topLevelSections   — now includes ANEXA top-level sections alongside ARTICOL
// $result->ambiguousCitationLabels — list<string>, empty for almost every real document; see
//                                     ParsedLegislationDocument's docblock for what a caller
//                                     (today: nothing — see "What is NOT done") should do with it.

LegislatieJustRoHtmlParser::VERSION; // '1.1.0' — bumped, record this on any re-parse.
```

`App\Domain\TaxKnowledge\Enums\SectionType::ANEXA` is a new public enum case; anything that
`match`es exhaustively over `SectionType` outside this task's scope needs to add a branch (checked:
nothing under `apps/api/app/**` outside `Domain/TaxKnowledge/Parsing/**` currently `match`es this
enum exhaustively without a `default`, per a repo-wide grep — see "Sources consulted").

## What is NOT done

- **`ambiguousCitationLabels` is not surfaced past the parser's own return value.**
  `LegislationSectionPersister`, `LegislationParsingResult`, and the `taxos:legislation:ingest` CLI
  command are all unmodified (out of this task's stated scope) — a human re-parsing the real
  document today gets the correct, deduplicated `source_sections` rows, but the 4 flagged labels
  are only visible by calling the parser directly and reading that field, not from the CLI's own
  output. Wiring this through is real follow-up work for whoever owns `Services/**` next.
- **The 4 genuinely-ambiguous source citations are not resolved, by design.** A human with access
  to the *actual gazetted paper text* of Art. 168's OUG 79/2017 amendment, Art. 342's OUG 4/2022
  amendment, and Art. 16's fiscal-year-change procedure could resolve all three with certainty;
  this task did not have that and did not guess.
- **`PUNCT` containing a nested `PUNCT`, or `ALINEAT` containing a nested `ALINEAT`, are not
  handled by any reattachment rule** — no real evidence of either pattern was found in the document
  (unlike `LITERA` continuing `ALINEAT`, which has five confirmed real occurrences). If a future
  document surfaces one, add the narrowly-scoped case with the same kind of quoted-HTML evidence
  this task used, not by widening `groupOrphanContinuations()`'s existing condition speculatively.
- **A colliding section that has its own children is untested** — all 4 real ambiguous cases found
  are leaves (no nested `LITERA`/`PUNCT`/`ALINEAT`), so `pruneAmbiguousCitations()` dropping a
  colliding subtree wholesale never had to prove it handles re-parenting real grandchildren. If a
  future document surfaces a colliding node *with* children, that behaviour (drop the whole
  subtree) is still the safe, honest default, but worth a second look before extending further.
- Did not touch `docs/STATUS.md` (explicit instruction), any file outside
  `apps/api/app/Domain/TaxKnowledge/Parsing/**`, or `resources/js/**`.

## Open questions

- Should `ambiguousCitationLabels` eventually become a persisted anomaly (mirroring
  `App\Domain\Fx\Models\FxRateAnomaly`'s "anomaly, don't overwrite silently" pattern) rather than
  an in-memory DTO field a caller must remember to check? That decision touches `Services/**` and
  is explicitly out of this task's scope — flagged for whoever picks up "What is NOT done"'s first
  item.
- Is there a real, gazetted way to resolve Art. 168's `(9^2)`/`(9^3)` labelling typo — e.g. does
  `legislatie.just.ro` itself ever correct it, or is it a standing defect in their own publication?
  Not investigated here (would require another live fetch, which this task's brief did not ask
  for and which risks re-triggering the exact "don't re-download" instruction).

## Sources consulted

- `docs/reference/fiscal-code-parse-defects.md` — the measured diagnosis this task's spec. Now
  updated in place with the resolved numbers and exact evidence (kept the original measurements,
  added the resolution below them, per this task's own instruction to update this file).
- `docs/AGENTS.md` — read in full before writing code; §1 (rules 2 "never invent legislation/
  citations", 8 "never disable a test/linter to pass CI"), §2 ("never fabricate... a plausible-
  looking placeholder that reads as real"), §7b (own database), §9 (fiscal work requirements),
  §10 (record cross-module findings in `docs/STATUS.md` rather than fix silently — not done here
  only because this task was explicitly told not to edit that file).
- `docs/CODE-QUALITY.md` — §1 (DRY with the blueprint's nuance — `sectionTypeOf()` extracted
  because the same lookup was about to be duplicated three ways, not prematurely abstracted), §2
  (no dead code/speculative scaffolding — the narrow `LITERA`-only reattachment rule and the
  explicit "do not widen without evidence" docblock exist specifically to resist over-
  generalising), §4 ("no catch-all exception handlers that swallow the error" — `sectionTypeOf()`
  throws, matching the existing `parseContainer()` pattern, rather than silently defaulting).
- `docs/handoff/018-legislation-ingestion.md` — read in full before touching the parser; the
  original design rationale (why `Titlu`/`Capitol`/`Secțiune` aren't modelled, why effective dates
  are never guessed, the `S_NTA`/`_SHORT` exclusion rationale) that this task extends rather than
  re-litigates.
- **The real Codul fiscal HTML itself**, `/var/www/html/storage/app/cf.html` inside `anaf-app-1`
  (already present, per this task's brief — never re-fetched from `legislatie.just.ro`), 7.6 MB,
  retrieved by an earlier session from `https://legislatie.just.ro/Public/DetaliiDocument/171282`.
  Every defect, every fix, and every number in this handoff and in
  `docs/reference/fiscal-code-parse-defects.md` was read directly from this file's real bytes
  using ad hoc diagnostic scripts (not committed — throwaway `/tmp/diag*.php` files run inside the
  container, deleted with the container's own `/tmp` on the next restart) instrumenting a copy of
  the parser to record which real DOM nodes it visited, never guessed or estimated.
- `BLUEPRINT.md` §6.8 (schema — confirmed `section_type` is a plain `string` column, so `ANEXA`
  needed no migration), §30/§39 (the citation-integrity chain this whole task exists to protect).
