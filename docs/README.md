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

## Rules for these documents

- `BLUEPRINT.md` is normative. If code disagrees with it, the code is wrong or an ADR is missing.
- An ADR is required for any decision a future agent might otherwise reverse by accident.
- A handoff note is required for every completed task. No exceptions.
- `reference/` holds only **verified** facts, each with the URL and the date it was checked.
  Never write an unverified fact here.
