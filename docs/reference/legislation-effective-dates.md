# Effective dates in Romanian legislation — what the source actually gives us

**Investigated:** 2026-09-08, against the real Codul fiscal (Legea 227/2015) at
`legislatie.just.ro/Public/DetaliiDocument/171282` (7.6 MB consolidated HTML).
Confirms and extends the finding in `docs/handoff/018-legislation-ingestion.md`.

## The finding

**There is no machine-readable effective-date field.** The portal exposes no structured metadata
(no `itemprop`, no dedicated date attribute) giving an article's entry into force. What exists is
**prose inside the legal text itself**.

Verbatim examples pulled from the live document:

> „…prin excepție de la alin. (1), prevederile **art. I pct. 9-51 intră în vigoare la data de
> 31 martie 2026**.” (OUG nr. 9 din 27 februarie 2026)

> „…în vigoare **până la data de 31 decembrie 2024 inclusiv**, aplică aceste prevederi până la
> încheierea anului fiscal modificat respectiv.”

## Why this is harder than "parse a date"

1. **Effective dates are per-provision, not per-act.** One amending act sets different dates for
   different point ranges (`art. I pct. 9-51`). An act's own publication date frequently is *not*
   the date its provisions take effect.
2. **They carry explicit exceptions.** `prin excepție de la alin. (1)` means the general rule in
   one paragraph is overridden for specific provisions.
3. **They can be relative, not absolute.** „…în termen de 90 de zile de la data intrării în vigoare
   a prezentei ordonanțe” — resolvable only by first resolving another date.
4. **They can be conditional on the taxpayer**, e.g. provisions that continue to apply to entities
   with a modified fiscal year until that year ends.

A regex over „intră în vigoare" would produce confident, wrong answers. That is the single most
damaging failure mode available to this project.

## What this means for the build

- **M3 is right to mark parsed versions `REQUIRES_REVIEW`** rather than infer a date. Do not
  "improve" this into a heuristic.
- **This is exactly the §9.2 AI-candidate use case**: a model reads the prose and *proposes*
  `effective_from` / `effective_until` per provision **with a citation to the section it read it
  from**. It never sets `APPROVED` (§9.3, `AGENTS.md` §9).
- **M4's human review workload is larger than a naive reading of the blueprint suggests.** It is not
  "check the numbers" — it is "confirm, per value, the date range in which it applied", against
  prose that is scoped, conditional and sometimes relative. This is the concrete reason M4 sits on
  the critical path and cannot be automated away.
- **M5's rule resolver must not assume act-level dating.** `tax_rule_versions.valid_from/valid_until`
  must be settable per rule, independently of the parent document's dates.

## Reproduce
```
curl -s -A "Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0 Safari/537.36" \
  -H "Accept-Language: ro-RO,ro;q=0.9" \
  "https://legislatie.just.ro/Public/DetaliiDocument/171282" > cf.html
grep -oiE ".{200}intră în vigoare.{120}" cf.html | head
```
(The browser User-Agent is required — the portal returns 403 without one.)
