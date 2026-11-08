#!/usr/bin/env bash
set -euo pipefail

# ---------------------------------------------------------------------------
# NOT IMPLEMENTED — this checks nothing, and says so on every run.
#
# BLUEPRINT.md §22 asks for these gates on any change to fiscal content:
#   - ruleset schema validation
#   - source-reference existence checks (every fiscal parameter / tax rule
#     version points at a real, approved source section)
#   - date-range overlap validation (no two versions of the same code with
#     overlapping [valid_from, valid_until))
#   - official ANAF golden-example tests (docs/AGENTS.md §9, BLUEPRINT.md §21.2)
#   - historical regression across fiscal years 2020-2026 (BLUEPRINT.md §21.3)
#
# The fiscal content they would check exists — the tax engine, its rules and
# the fiscal parameters, in packages/tax-romania — and the gates do not yet.
# Until they do this job passes and raises a warning annotation, so the gap is
# on every run's summary page rather than hidden behind a green tick, and CI
# does not let a release wait on it: a gate that checks nothing must not be
# able to look like one that passed.
#
# Plug the real checks in here, then make `containers` wait for this job.
# ---------------------------------------------------------------------------

FISCAL_CODE=(
    packages/tax-romania/src/TaxEngine
    packages/tax-romania/src/TaxRules
    packages/tax-romania/src/FiscalParameters
)

present=()
for path in "${FISCAL_CODE[@]}"; do
    [[ -d "$path" ]] && present+=("$path")
done

if [[ ${#present[@]} -eq 0 ]]; then
    # The paths above moved or were renamed: a warning about code that is not there would be the
    # same false comfort this script exists to avoid. Fail, so the list is brought up to date.
    echo "FAIL: none of the fiscal code paths this script names exist any more:" >&2
    printf '  %s\n' "${FISCAL_CODE[@]}" >&2
    echo "Point FISCAL_CODE at where the tax engine, rules and parameters live now." >&2
    exit 1
fi

echo "::warning title=Fiscal gates not implemented::Tax code exists (${present[*]}) but none of the BLUEPRINT.md §22 fiscal gates do. Nothing fiscal was checked."
echo "Fiscal gates: NOT IMPLEMENTED. Present fiscal code:"
printf '  %s\n' "${present[@]}"
echo "Nothing about any fiscal number was checked."
