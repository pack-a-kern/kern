# Fiscal Code parse — measured defects (RESOLVED)

**Measured:** 2026-09-08, running `LegislatieJustRoHtmlParser` against the **real** Codul fiscal
(Legea 227/2015), `legislatie.just.ro/Public/DetaliiDocument/171282`, 7.6 MB.

**Resolved:** 2026-09-08, same day — see `docs/handoff/039-fiscal-code-parser-fix.md` for the full
fix, evidence, and verification. This file keeps the original measured defects (below, unedited)
and adds the resolved numbers with their exact, evidenced explanations (also below) — nothing here
was "tuned" to make a count match; every remaining gap is independently explained by real HTML.

The parser was originally validated against the **Constitution** (614 sections, clean). The Fiscal
Code is larger and far more amended, and it broke the parser in three measurable ways.

## Original measured output (defective parser, before the fix)

| Section type | Parser produced | Source markers (`class="S_*"`) | Delta |
|---|---|---|---|
| ARTICOL | 645 | 645 (`S_ART`) | ✅ exact |
| ALINEAT | 3 015 | 3 019 (`S_ALN`) | **−4** |
| LITERA | **2 501** | **2 734** (`S_LIT`) | **−233 dropped** |
| PUNCT | 542 | 631 (`S_PCT`) | −89 |

Distinct citation labels: **6 663**. **Duplicate citation labels: 37.**

## Resolved output (fixed parser, `LegislatieJustRoHtmlParser::VERSION` `1.1.0`)

| Section type | Parser produces | Source markers (`class="S_*"`) | Delta | Explanation |
|---|---|---|---|---|
| ARTICOL | 645 | 645 (`S_ART`) | ✅ exact | — |
| ALINEAT | 3 013 | 3 019 (`S_ALN`) | **−6, fully explained** | 4 are a *different* act's own alineat, quoted verbatim inside an editorial `S_NTA` note for context (never a Codul fiscal provision — correctly excluded at any nesting depth); 2 are genuinely ambiguous source duplicates, pruned and reported rather than persisted twice (see below). |
| LITERA | 2 731 | 2 734 (`S_LIT`) | **−3, fully explained** | 1 is a *different* act's own literă, quoted verbatim inside an editorial `S_NTA` note (same reason as above); 2 are a genuinely ambiguous source duplicate, pruned and reported. |
| PUNCT | **631** | **631** (`S_PCT`) | ✅ **exact** (was −89) | The recursive body-content fix (see "Defect 3" below) also fixed every dropped `PUNCT`, as a side effect of being a generic container-recursion fix, not a `PUNCT`-specific patch. |
| ANEXA (new) | 9 | 9 (`S_ANX`) | ✅ exact | Newly modelled — see "New discovery" below. |

Distinct citation labels: **7 029**. **Duplicate citation labels: 0.**

Real, live, real-Postgres ingestion of the full 7.6 MB document (`docs/handoff/039-fiscal-code-parser-fix.md`
"How to verify") persisted all 7 029 rows with **zero** `source_sections_citation_unique`
violations — the exact constraint that previously aborted the whole ingestion.

## Defect 1 — letters collapse onto the article, losing their alineat (FIXED)
```
x2  Art. 40^3 lit. a)      x2  Art. 40^3 lit. c)      x3  Art. 316 lit. a)
x2  Art. 40^3 lit. b)      x2  Art. 40^3 lit. d)       ... lit. b) / lit. c)
```
**Root cause, confirmed from the real HTML**: when an `ALINEAT`'s own body contains an editorial
amendment note (an `S_PAR` reading e.g. `"(la 03-09-2021, ... a fost modificată de ...)"`), the
site's own markup closes that `ALINEAT`'s tag immediately after the note — *before* the lettered
items its own introductory sentence promised (`"... următoarele situații:"`). Those `S_LIT`
elements land as direct siblings of the `S_ALN` in the parent `S_ART_BDY`, not nested inside it.
Confirmed in five places: Art. 40^3 (two groups) and Art. 316 (three groups, each preceded by its
own dated amendment note).

**Fix**: `LegislatieJustRoHtmlParser::groupOrphanContinuations()` — a structural repair using only
DOM order, never legal-content inference. A `LITERA` found as a direct child of a body is
reattached to the *immediately preceding sibling* only when that sibling is an `ALINEAT`. Correct
output for Art. 40^3's first group is now `Art. 40^3 alin. (4) lit. a)` … `lit. d)` (the source's
own text: *"... beneficiază de dreptul de eșalonare la plată ... dacă se află în oricare dintre
următoarele situații:"* followed directly by the four lettered situations).

**Deliberately narrow**: the rule only reattaches `LITERA` onto a preceding `ALINEAT` — nothing
else. An earlier, broader version also reattached `PUNCT` onto a preceding `ALINEAT`/`PUNCT`,
which broke Art. 316 alin. (1) lit. a) — a *correct*, unrelated structure where two independent
`PUNCT` siblings (`pct. 1.`, `pct. 2.`) are legitimately nested side by side inside one `LITERA`.
The regression test in `LegislatieJustRoHtmlParserFiscalCodeExtractTest.php` caught this against
the real extract before it shipped. Do not widen this rule without the same kind of measured HTML
evidence cited here.

## Defect 2 — orphan letters with no article at all (FIXED)
```
x2  lit. a)   x2  lit. b)   x2  lit. c)   x2  lit. d)   ... through lit. z), lit. ș), lit. ț)
```
**Root cause, confirmed from the real HTML**: these are not a parser bug in the way Defect 1 is —
they are real, citable content inside **`S_ANX` (Anexă/Annex)** containers, a genuine structural
level the parser never modelled. The real Codul fiscal has **9 real annexes**, each containing
lettered/numbered lists — e.g. a company-form classification list (`Titlul II Anexa nr. 1`, 28
lettered items) and excise-duty product tables (`Titlul VIII Anexa nr. 1`/`nr. 2`). Because `S_ANX`
wasn't in `SectionType`, `findTopLevelContainers()` walked straight through it looking for the
first recognised container and found the nested `S_LIT`/`S_ALN` elements with **no ancestor at
all** — worse than Defect 1, exactly as this file originally said.

**Fix**: `SectionType::ANEXA` (new case) + `S_ANX` added to `CONTAINER_CLASSES`. Annex numbering
restarts per `Titlu` — the real document has **three separate "Anexa nr. 1"s** and **three
separate "Anexa nr. 2"s**, one per enclosing `Titlu` — so `ancestorWrapperContext()` walks the
`S_TTL`/`S_CAP`/`S_SEC` wrapper chain above an annex and prefixes its citation with their real,
verbatim label text: `Titlul II Anexa nr. 1`, `Titlul VII Capitolul XV Anexa`, `Titlul VIII Anexa
nr. 2`, etc. All 9 real annexes now resolve to 9 distinct, human-recognisable citations — verified
directly against the real document, see the handoff note's "Spot-checked citations".

## Defect 3 — letters silently dropped (FIXED)
`S_LIT` appeared 2 734 times; the parser emitted only 2 501 `LITERA` sections; 233 were genuinely
absent from the parse tree. **Root cause, confirmed from the real HTML — not what this file
originally guessed**: it is not about `S_LIT_TTL`/`S_LIT_BDY` token recognition (those were always
read correctly, by concatenating the container token with `_TTL`/`_BDY`). The real cause: the
original parser only searched a body's **direct children** for nested containers. Real content —
e.g. Art. 3's eight fiscal principles — nests a `LITERA` **inside a plain `S_PAR` wrapper**, which
is itself the direct child of the body:
```html
<span class="S_ART_BDY">
  <span class="S_PAR">Impozitele și taxele ... următoarele principii:
    <span class="S_LIT">...a)...</span>
    <span class="S_LIT">...b)...</span>
    ...
  </span>
</span>
```
A direct-children-only scan folded that whole `S_PAR` subtree into the article's own text (via
`textContent`, silently including the letters' words without ever giving them a row) and never
found the nested `S_LIT` elements — not mislabelled, absent.

**Fix**: `collectBodyContent()` replaces the direct-children-only scan with a **recursive descent**
(the same depth-first strategy `findTopLevelContainers()` already used at the document root):
descend into a plain wrapper looking for a container; stop the instant one is found (that subtree
becomes a child, never touched for text); skip an annotation subtree entirely at any depth. This
is a generic container-recursion fix, not `LITERA`-specific — it is also why `PUNCT` went from 542
(−89 unexplained by the original brief) to the source-exact 631 with no additional code.

**233 accounted for exactly**: 230 were the direct-descent fix above; the remaining 3 (2 734 − 2
731 in the final, resolved parser) are independently explained below, not silently dropped.

## New discovery — 233 wasn't the whole story, and neither was "37 duplicates"
Fixing Defects 1–3 (all genuine parser bugs) surfaced **4 additional, genuinely ambiguous source
citations** that Defect 3's fix now finds but cannot resolve without inventing content:

1. **`Art. 16 alin. (5^1) lit. a)` / `lit. b)`** — this one alineat legitimately contains **two
   independent lettered enumerations**: rules for the modified fiscal year (`lit. a)`/`b)` nested
   directly), then a second `S_PAR` reading *"Contribuabilii comunică organelor fiscale competente
   modificarea perioadei anului fiscal astfel:"* with its **own** `lit. a)`/`b)` describing the
   notification procedure (which even cross-references the first list's `lit. a)`/`b)` via
   `S_LGI`). Both are real; nothing in the markup numbers them differently.
2. **`Art. 168 alin. (9^2)`** (×2) — the source's own `S_ALN_TTL` literally renders `"(9^2)"` for
   two different alineate. The *second* one's own amendment note says `"Alineatul (9^3) ... a fost
   abrogat"` — the site's own label is a typo for `(9^3)`. Both bodies read `"Abrogat."` (repealed).
3. **`Art. 342 alin. (1^3)`** (×2) — the source renders the *identical* paragraph, including its
   identical amendment note (same date, same `ORDONANȚA nr. 4 din 27 ianuarie 2022`), **twice,
   verbatim** — a genuine rendering duplication on the site's side, not two provisions.

None of these can be told apart from the markup alone; inventing a distinguishing suffix would
violate the "never fabricate a citation" rule as much as leaving the collision in place. The fix:
`LegislatieJustRoHtmlParser::pruneAmbiguousCitations()` keeps the **first** occurrence (reading
order) as the real, citable row and **drops** every later colliding occurrence, recording its label
in the new `ParsedLegislationDocument::$ambiguousCitationLabels` — a documented, surfaced gap, not
a silent guess. See `docs/handoff/039-fiscal-code-parser-fix.md` for the full reasoning.

## Why this matters
`BLUEPRINT.md` §30/§39 require every fiscal figure to trace to **one** unambiguous source provision.
A duplicate citation means a tax value can cite text that is not its source, and a dropped
provision means a rule may have no citable basis at all. The unique constraint
`source_sections_citation_unique` caught it — the database refused to store an ambiguous citation,
which is the schema doing exactly its job. It still does: the 4 genuinely ambiguous source
citations above never reach it twice.

## Reproduce
```
docker cp cf.html anaf-app-1:/var/www/html/storage/app/cf.html
# parse and walk the tree, counting sectionType and duplicate citationLabel
```
Before the fix, the ingestion command failed with:
`SQLSTATE[23505] duplicate key ... (document_version_id, citation_label)=(…, "Art. 40^3 lit. a)")`

After the fix, real ingestion of the full 7.6 MB document persists all 7 029 sections with zero
constraint violations — see `docs/handoff/039-fiscal-code-parser-fix.md` "How to verify" for the
exact real output.
