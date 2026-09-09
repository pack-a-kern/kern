#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# PLACEHOLDER — this is NOT a real fiscal validation gate.
#
# BLUEPRINT.md §22 requires, once Milestones 4 (historical PF dataset) and 5
# (deterministic tax engine) exist:
#   - ruleset schema validation
#   - source-reference existence checks (every fiscal_parameter/tax_rule
#     version points at a real, approved source_section_id)
#   - date-range overlap validation (no two rule/parameter versions with
#     overlapping [valid_from, valid_until) for the same code)
#   - official ANAF golden-example tests (docs/AGENTS.md §9, BLUEPRINT.md §21.2)
#   - historical regression across fiscal years 2020-2026 (BLUEPRINT.md §21.3)
#
# None of that exists yet — see docs/STATUS.md, milestones M4/M5. Plug the
# real checks in here (or replace this script with real tooling under
# apps/api or a dedicated CI script) as soon as either of the following
# exists:
#   - tax-rules/ro/<year>/*.yaml (ruleset manifests, BLUEPRINT.md §10.2)
#   - apps/api/app/Domain/TaxEngine/** (rule implementations, ADR 0004)
#
# This script only checks that those don't exist yet. The moment either
# appears, it fails loudly instead of silently continuing to "pass" — a
# green fiscal gate must never be allowed to mean "no rules exist to check"
# once rules actually exist. Do not weaken or remove this canary to make CI
# green; implement the real gate instead (docs/AGENTS.md §9, BLUEPRINT.md §22
# "Tax changes" gates).
# ---------------------------------------------------------------------------

echo "=== Fiscal gates: PLACEHOLDER — not yet implemented (Milestone 4/5) ==="

found_rulesets=$(find tax-rules -type f \( -name '*.yaml' -o -name '*.yml' \) 2>/dev/null || true)
found_tax_engine=$(find apps/api/app/Domain/TaxEngine -type f -name '*.php' 2>/dev/null || true)

if [[ -n "$found_rulesets" || -n "$found_tax_engine" ]]; then
  echo "FAIL: real fiscal content was found, but this CI gate is still a placeholder." >&2
  echo >&2
  echo "Ruleset files found:" >&2
  echo "$found_rulesets" >&2
  echo "TaxEngine files found:" >&2
  echo "$found_tax_engine" >&2
  echo >&2
  echo "Implement the real checks required by BLUEPRINT.md §22 before merging:" >&2
  echo "  - ruleset schema validation" >&2
  echo "  - source-reference existence checks" >&2
  echo "  - date-range overlap validation" >&2
  echo "  - official ANAF golden-example tests" >&2
  echo "  - historical regression across 2020-2026" >&2
  exit 1
fi

echo "No ruleset manifests (tax-rules/ro/**/*.yaml) and no TaxEngine implementation"
echo "(apps/api/app/Domain/TaxEngine/**) exist yet — Milestone 4/5 not started."
echo "This placeholder passes trivially. It is NOT proof that any fiscal number"
echo "is correct. See docs/handoff/008-ci-pipeline.md for where to plug in the"
echo "real gate."
exit 0
