# Official ANAF golden test fixtures

`docs/CODE-QUALITY.md` §5 / `docs/AGENTS.md` §9: "Every official fiscal example transformed into a
test... CI must demonstrate: implementarea produce exact exemplul ANAF."

This directory is **deliberately empty** as of Milestone 5. No official ANAF numeric example has
been ingested, reviewed, and approved yet — Milestone 4 (the historical PF fiscal dataset,
2020–2026) is explicitly blocked pending human review (`BLUEPRINT.md` §26 Milestone 4). Inventing an
example here to fill this directory would violate `docs/AGENTS.md` §2 ("Never fabricate... Do not
write a plausible-looking placeholder that reads as real").

## What goes here, once it exists

One subdirectory per fiscal year, each fixture carrying:

```text
tests/Fixtures/Official/ANAF/{year}/{case-name}.json
```

```json
{
  "source_id": "<source_document_version_id or citation the example was taken from>",
  "input": { "...": "..." },
  "expected": { "...": "..." },
  "notes": "..."
}
```

Every fixture must cite a real, verifiable `source_section_id`/document — never a number typed
from memory or inferred from a blog post (`BLUEPRINT.md` §8.1's source hierarchy: only Tier A/B/C
official sources justify a fiscal figure).

## How a future task wires this in

1. M4 approves a `fiscal_parameter_versions`/`tax_rule_versions` row with a real
   `source_section_id`.
2. A real `App\Domain\TaxEngine\Contracts\TaxRuleContract` implementation is written for that rule,
   under `app/Domain/TaxEngine/Rules/**` (an empty directory today — `docs/CODE-QUALITY.md` §2
   forbids scaffolding it before there is a rule to put there).
3. The official ANAF worked example becomes a fixture here.
4. A Pest test loads the fixture, builds a `TaxContext` from its `input`, calls
   `App\Domain\TaxEngine\Services\TaxAssessor::assess()`, and asserts the result equals `expected`
   exactly — the CI gate `docs/AGENTS.md` §9 requires for any change touching a computed figure.
