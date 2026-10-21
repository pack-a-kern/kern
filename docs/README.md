# docs/ — how this repository documents itself

Read in this order when you join the project:

| File | What it gives you |
|------|-------------------|
| `../BLUEPRINT.md` | The blueprint. Source of truth for everything. |
| **`ESSENTIALS.md`** | **Start here.** One page: the no-fabrication rule, the traps that have already bitten, how to run things fast, definition of done. |
| `AGENTS.md` | Full binding rules. Read when your task needs the detail. |
| `STATUS.md` | What exists, what is in flight, what is blocked. |
| `ARCHITECTURE.md` | Module map and boundaries. |
| `adr/` | Architectural decisions and why they were made. |
| `handoff/` | One note per completed task — how to continue someone else's work. |
| `reference/` | Verified external facts: versions, endpoints, formats. |
| `DEPLOYMENT.md` | Running this on a real server: `make install`, what each container is, every variable, and what is not production-ready yet. |
| `SECURITY-FINDINGS.md` | Twenty findings from five audits of 2026-10-19, numbered to work through one at a time, plus the controls that were verified sound. |
| `PLANNED-FEATURES.md` | Features agreed on and still to design together: sharing a taxpayer, backups. A plan, not a record. |
| `INSTALLER-PLAN.md` | Planned work: one command to install on a fresh server. A plan, not a record. |

## Rules for these documents

- `BLUEPRINT.md` is normative. If code disagrees with it, the code is wrong or an ADR is missing.
- An ADR is required for any decision a future agent might otherwise reverse by accident.
- A handoff note is required for every completed task. No exceptions.
- `reference/` holds only **verified** facts, each with the URL and the date it was checked.
  Never write an unverified fact here.
